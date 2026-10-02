<?php

namespace App\Automation;

use App\Binance\BinanceAccountClient;
use App\Binance\DynamicCapitalCalculator;
use App\Console\Commands\ProcessAutomaticTradingCommand;
use App\MarketData\Candle;
use App\Models\ActiveStrategy;
use App\Models\ActiveTradingCycle as ActiveTradingCycleModel;
use App\Models\Asset;
use App\Models\BotEvent;
use App\Models\Order;
use App\Models\Trade;
use App\Risk\RiskManager;
use App\Strategy\Signal;
use App\Strategy\SignalType;
use App\Strategy\Strategy;
use App\Strategy\StrategyFactory;
use App\Trading\ActiveTradingCycleState;
use Carbon\CarbonImmutable;

/**
 * Processes one already-fetched batch of candles for an {@see ActiveStrategy}:
 * generates a signal, applies the existing open-position rule (a BUY never
 * opens a second position for the same asset, a SELL only closes an already
 * open one), risk-checks it, simulates the fill, and persists the result.
 *
 * This is a single, side-effecting step with no loop or sleep of its own —
 * {@see ProcessAutomaticTradingCommand} decides when to
 * call it. Everything it needs (which strategy, symbol, capital) is read
 * from the database via $active, so it works the same whether the process
 * just started or has been running for hours.
 *
 * Fase 4: when $active has a linked, still-open {@see ActiveTradingCycleModel}
 * (see `resolveCycle()` — not every ActiveStrategy has one; Manual mode
 * applies a strategy without creating a cycle), a BUY that actually opens a
 * position moves it Hold -> PositionOpen, and a SELL that actually closes one
 * moves it PositionOpen -> Closed *and* stops $active (see
 * {@see ActiveStrategy::stopIfRunning()}) — a closed cycle must never leave
 * its strategy still `running`. Every {@see BotEvent} this class records is
 * tagged with that cycle's id when one is resolved, so the cycle's history
 * can be read back precisely instead of guessed from (account, asset, time).
 */
final class AutomaticTradingCycle
{
    private readonly DynamicCapitalCalculator $capitalCalculator;

    public function __construct(
        private readonly RiskManager $riskManager = new RiskManager,
        private readonly SimulatedTradeExecutor $executor = new SimulatedTradeExecutor,
        ?DynamicCapitalCalculator $capitalCalculator = null,
    ) {
        $this->capitalCalculator = $capitalCalculator ?? new DynamicCapitalCalculator(new BinanceAccountClient(
            baseUrl: config('services.binance.base_url'),
            apiKey: config('services.binance.api_key'),
            apiSecret: config('services.binance.api_secret'),
        ));
    }

    /**
     * @param  Candle[]  $candles  chronologically ordered (oldest first)
     */
    public function process(ActiveStrategy $active, array $candles): void
    {
        $strategy = StrategyFactory::fromModel($active->strategy);
        $signal = $strategy->generate($candles);
        $lastCandle = $candles[array_key_last($candles)];
        $currentPrice = $lastCandle->close;
        $asset = $this->resolveAsset($active->symbol);
        $cycle = $this->resolveCycle($active);

        $this->recordEvent($active, $cycle, 'candle_processed', $asset->symbol, "Signal {$signal->type->value}: {$signal->reason}", [
            'signal' => $signal->type->value,
            'price' => $currentPrice,
        ]);

        if ($active->pending_sell_candle_at !== null
            && $this->resolvePendingSell($active, $cycle, $asset, $strategy, $candles, $currentPrice)) {
            return;
        }

        if ($signal->type === SignalType::SELL) {
            if ($this->openTrade($active, $asset) === null) {
                $this->handleSell($active, $cycle, $asset, $currentPrice);

                return;
            }

            $this->registerPendingSell($active, $cycle, $asset, $lastCandle, $currentPrice);
        }

        if ($this->handleRiskExit($active, $cycle, $asset, $currentPrice)) {
            return;
        }

        if ($signal->type === SignalType::BUY) {
            $this->handleBuy($active, $cycle, $asset, $signal, $currentPrice);
        }
    }

    /**
     * Experiment: a bearish crossover does not sell immediately. The closed
     * candle that shows it (candle A) only leaves the SELL pending; the
     * following closed candle (candle B) confirms or cancels it. A crossover
     * already pending for this same candle (the scheduler re-evaluating the
     * same closed candle) is not registered twice.
     */
    private function registerPendingSell(ActiveStrategy $active, ?ActiveTradingCycleModel $cycle, Asset $asset, Candle $candle, string $currentPrice): void
    {
        if ($active->pending_sell_candle_at?->equalTo($candle->timestamp)) {
            return;
        }

        $active->update(['pending_sell_candle_at' => $candle->timestamp]);

        $this->recordEvent($active, $cycle, 'signal_sell_pending_confirmation', $asset->symbol,
            "Cruce bajista en {$asset->symbol}: SELL pendiente de confirmación en la siguiente vela cerrada.",
            ['candle_at' => $candle->timestamp->toIso8601String(), 'price' => $currentPrice]);
    }

    /**
     * Resolves the pending SELL using only the closed candle that immediately
     * follows the crossover candle (candle B), even if the scheduler skipped
     * a run and newer candles exist by now. B still below (any signal other
     * than BUY, i.e. short <= long) confirms and executes the SELL; B above
     * (BUY, short > long) cancels it. A pending SELL ends here exactly once
     * (handleSell() and cancelPendingSell() both clear it), so it can never
     * produce more than one SELL.
     *
     * @param  Candle[]  $candles
     * @return bool whether the position was sold
     */
    private function resolvePendingSell(ActiveStrategy $active, ?ActiveTradingCycleModel $cycle, Asset $asset, Strategy $strategy, array $candles, string $currentPrice): bool
    {
        $pendingAt = $active->pending_sell_candle_at;

        if ($this->openTrade($active, $asset) === null) {
            $this->cancelPendingSell($active, $cycle, $asset, 'no_open_position');

            return false;
        }

        $crossoverIndex = null;
        foreach ($candles as $index => $candle) {
            if ($candle->timestamp->equalTo($pendingAt)) {
                $crossoverIndex = $index;
                break;
            }
        }

        if ($crossoverIndex === null) {
            $this->cancelPendingSell($active, $cycle, $asset, 'crossover_candle_not_available');

            return false;
        }

        if (! isset($candles[$crossoverIndex + 1])) {
            return false;
        }

        $confirmation = $strategy->generate(array_slice($candles, 0, $crossoverIndex + 2));

        if ($confirmation->type === SignalType::BUY) {
            $this->cancelPendingSell($active, $cycle, $asset, 'recovered');

            return false;
        }

        $this->recordEvent($active, $cycle, 'signal_sell_confirmed', $asset->symbol,
            "SELL confirmado en {$asset->symbol}: la vela siguiente sigue con la media corta por debajo de la larga.",
            [
                'crossover_candle_at' => $pendingAt->toIso8601String(),
                'confirmation_candle_at' => $candles[$crossoverIndex + 1]->timestamp->toIso8601String(),
            ]);

        $this->handleSell($active, $cycle, $asset, $currentPrice);

        return true;
    }

    /**
     * Drops a pending SELL without selling. $reason: `recovered` (the next
     * candle put the short average back above the long one), `risk_exit`
     * (stop loss / max holding closed the position first), `no_open_position`
     * or `crossover_candle_not_available`.
     */
    private function cancelPendingSell(ActiveStrategy $active, ?ActiveTradingCycleModel $cycle, Asset $asset, string $reason): void
    {
        $crossoverAt = $active->pending_sell_candle_at;

        if ($crossoverAt === null) {
            return;
        }

        $active->update(['pending_sell_candle_at' => null]);

        $this->recordEvent($active, $cycle, 'signal_sell_confirmation_cancelled', $asset->symbol,
            "SELL cancelado en {$asset->symbol}: no se confirmó en la vela siguiente ({$reason}).",
            ['crossover_candle_at' => $crossoverAt->toIso8601String(), 'reason' => $reason]);
    }

    /**
     * Safety net for an open position when the strategy did not say SELL:
     * closes it on stop loss or max holding time (see `trading.risk_exit`),
     * recording `risk_stop_loss` / `risk_time_exit` before `position_closed`.
     *
     * @return bool whether the position was closed
     */
    private function handleRiskExit(ActiveStrategy $active, ?ActiveTradingCycleModel $cycle, Asset $asset, string $currentPrice): bool
    {
        $trade = $this->openTrade($active, $asset);

        if ($trade === null) {
            return false;
        }

        $stopLossPercent = (string) config('trading.risk_exit.stop_loss_percent');
        $maxHoldingHours = (int) config('trading.risk_exit.max_holding_hours');

        if (bccomp($stopLossPercent, '0', 4) > 0 && bccomp($trade->entry_price, '0', 18) > 0) {
            $lossPercent = bcmul(bcdiv(bcsub($trade->entry_price, $currentPrice, 18), $trade->entry_price, 18), '100', 4);

            if (bccomp($lossPercent, $stopLossPercent, 4) >= 0) {
                $this->recordEvent($active, $cycle, 'risk_stop_loss', $asset->symbol,
                    "Stop loss: {$asset->symbol} perdió {$lossPercent}% desde la entrada (límite {$stopLossPercent}%).",
                    ['trade_id' => $trade->id, 'loss_percent' => $lossPercent]);
                $this->cancelPendingSell($active, $cycle, $asset, 'risk_exit');
                $this->handleSell($active, $cycle, $asset, $currentPrice);

                return true;
            }
        }

        if ($maxHoldingHours > 0 && $trade->opened_at->copy()->addHours($maxHoldingHours)->lte(CarbonImmutable::now())) {
            $this->recordEvent($active, $cycle, 'risk_time_exit', $asset->symbol,
                "Salida por tiempo: {$asset->symbol} lleva abierta {$maxHoldingHours}h o más.",
                ['trade_id' => $trade->id, 'max_holding_hours' => $maxHoldingHours]);
            $this->cancelPendingSell($active, $cycle, $asset, 'risk_exit');
            $this->handleSell($active, $cycle, $asset, $currentPrice);

            return true;
        }

        return false;
    }

    /**
     * The still-open {@see ActiveTradingCycleModel} this active strategy is
     * executing, if any. Null for Manual mode's {@see ActiveStrategy} rows,
     * which are applied directly (via `ActivateStrategyAction`) without ever
     * creating a cycle.
     */
    private function resolveCycle(ActiveStrategy $active): ?ActiveTradingCycleModel
    {
        return $active->activeTradingCycles()->active()->latest('id')->first();
    }

    private function handleBuy(ActiveStrategy $active, ?ActiveTradingCycleModel $cycle, Asset $asset, Signal $signal, string $currentPrice): void
    {
        if ($this->openTrade($active, $asset) !== null) {
            $this->recordEvent($active, $cycle, 'signal_ignored_position_open', $asset->symbol,
                "BUY ignored: a position for {$asset->symbol} is already open.");

            return;
        }

        $capital = $this->resolveBuyCapital($active, $cycle, $asset);

        if ($capital === null) {
            return;
        }

        $openTradesCount = Trade::query()->where('account_id', $active->account_id)->where('status', 'open')->count();

        $assessment = $this->riskManager->evaluate(
            $signal,
            $capital,
            $capital,
            $active->tradingAccount->riskSetting,
            $openTradesCount,
        );

        if (! $assessment->allowed) {
            $this->recordEvent($active, $cycle, 'signal_rejected_by_risk', $asset->symbol, $assessment->reason);

            return;
        }

        $execution = $this->executor->buy($asset->symbol, $currentPrice, $assessment->positionSize);

        $trade = Trade::query()->create([
            'account_id' => $active->account_id,
            'strategy_id' => $active->strategy_id,
            'active_strategy_id' => $active->id,
            'asset_id' => $asset->id,
            'entry_price' => $execution->executedPrice,
            'quantity' => $execution->quantity,
            'capital_used' => $execution->capitalUsed,
            'status' => 'open',
            'opened_at' => CarbonImmutable::now(),
        ]);

        $this->recordOrder($trade, 'buy', $execution->executedPrice, $execution->quantity);

        $this->recordEvent($active, $cycle, 'position_opened', $asset->symbol, $execution->reason, [
            'trade_id' => $trade->id,
        ]);

        if ($cycle !== null && $cycle->state === ActiveTradingCycleState::Hold) {
            $cycle->update(['state' => ActiveTradingCycleState::PositionOpen]);
        }
    }

    /**
     * The capital this BUY may use. A cycle-linked ActiveStrategy (Modo
     * Automático — see `resolveCycle()`) never uses its own stored
     * `capital`: it always gets a fresh
     * `balance disponible x (1 - reserve) / MAX_ACTIVE_CYCLES` share from
     * {@see DynamicCapitalCalculator}, computed at this exact moment so it
     * reflects the account's real balance right when the position opens,
     * not a stale value from when the slot's candidate was activated. A
     * cycle-less ActiveStrategy (Manual "Aplicar" — no slot concept applies)
     * keeps using its own configured `capital`, unchanged.
     *
     * Returns null — after recording why — when the computed capital does
     * not clear `trading.capital.min_notional_usdt`: sending an order for an
     * amount too small to be valid is unsafe, so the BUY is skipped instead
     * of attempted.
     */
    private function resolveBuyCapital(ActiveStrategy $active, ?ActiveTradingCycleModel $cycle, Asset $asset): ?string
    {
        if ($cycle === null) {
            return $active->capital;
        }

        $capital = $this->capitalCalculator->capitalPerSlot();
        $minNotional = (string) config('trading.capital.min_notional_usdt');

        if (bccomp($capital, $minNotional, 18) < 0) {
            $this->recordEvent($active, $cycle, 'signal_rejected_insufficient_capital', $asset->symbol,
                "BUY omitido: capital por slot ({$capital} USDT) por debajo del mínimo operable ({$minNotional} USDT).",
                ['capital_per_slot' => $capital, 'min_notional_usdt' => $minNotional]);

            return null;
        }

        return $capital;
    }

    private function handleSell(ActiveStrategy $active, ?ActiveTradingCycleModel $cycle, Asset $asset, string $currentPrice): void
    {
        $trade = $this->openTrade($active, $asset);

        if ($trade === null) {
            $this->recordEvent($active, $cycle, 'signal_ignored_no_open_position', $asset->symbol,
                "SELL ignored: no open position for {$asset->symbol}.");

            return;
        }

        $active->update(['pending_sell_candle_at' => null]);

        $execution = $this->executor->sell($asset->symbol, $currentPrice, $trade->quantity);

        $profitLoss = bcsub($execution->capitalUsed, $trade->capital_used, 8);
        $profitLossPercent = bccomp($trade->capital_used, '0', 18) > 0
            ? bcmul(bcdiv($profitLoss, $trade->capital_used, 18), '100', 4)
            : '0';

        $trade->update([
            'exit_price' => $execution->executedPrice,
            'profit_loss' => $profitLoss,
            'profit_loss_percent' => $profitLossPercent,
            'status' => 'closed',
            'closed_at' => CarbonImmutable::now(),
        ]);

        $this->recordOrder($trade, 'sell', $execution->executedPrice, $execution->quantity);

        $this->recordEvent($active, $cycle, 'position_closed', $asset->symbol, $execution->reason, [
            'trade_id' => $trade->id,
            'profit_loss' => $profitLoss,
        ]);

        if ($cycle !== null && $cycle->state === ActiveTradingCycleState::PositionOpen) {
            $cycle->update(['state' => ActiveTradingCycleState::Closed]);
            $active->stopIfRunning();

            $this->recordEvent($active, $cycle, 'cycle_closed', $asset->symbol,
                "Ciclo cerrado tras SELL en {$asset->symbol}.", ['trade_id' => $trade->id, 'profit_loss' => $profitLoss]);
        }
    }

    private function openTrade(ActiveStrategy $active, Asset $asset): ?Trade
    {
        return Trade::query()
            ->where('account_id', $active->account_id)
            ->where('asset_id', $asset->id)
            ->where('status', 'open')
            ->first();
    }

    private function recordOrder(Trade $trade, string $side, string $price, string $quantity): void
    {
        Order::query()->create([
            'trade_id' => $trade->id,
            'type' => 'market',
            'side' => $side,
            'price' => $price,
            'quantity' => $quantity,
            'status' => 'filled',
            'executed_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    private function recordEvent(ActiveStrategy $active, ?ActiveTradingCycleModel $cycle, string $eventType, ?string $asset, string $message, ?array $data = null): void
    {
        BotEvent::query()->create([
            'account_id' => $active->account_id,
            'active_trading_cycle_id' => $cycle?->id,
            'event_type' => $eventType,
            'asset' => $asset,
            'message' => $message,
            'data' => $data,
        ]);
    }

    private function resolveAsset(string $symbol): Asset
    {
        $symbol = strtoupper($symbol);

        return Asset::query()->firstOrCreate(
            ['exchange' => 'binance', 'symbol' => $symbol],
            [
                'base_asset' => $this->baseAsset($symbol),
                'quote_asset' => $this->quoteAsset($symbol),
                'is_active' => true,
            ],
        );
    }

    /**
     * Best-effort split of a symbol like "BTCUSDT" into base/quote assets by
     * testing known quote suffixes; `base_asset`/`quote_asset` are not
     * nullable, so an unrecognized symbol falls back to the full symbol as
     * base and "UNKNOWN" as quote rather than guessing a wrong split.
     */
    private function quoteAsset(string $symbol): string
    {
        foreach (['USDT', 'BUSD', 'USDC', 'BTC', 'ETH'] as $quote) {
            if (str_ends_with($symbol, $quote) && $symbol !== $quote) {
                return $quote;
            }
        }

        return 'UNKNOWN';
    }

    private function baseAsset(string $symbol): string
    {
        $quote = $this->quoteAsset($symbol);

        return $quote === 'UNKNOWN' ? $symbol : substr($symbol, 0, -strlen($quote));
    }
}
