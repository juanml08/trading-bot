<?php

namespace App\Automation;

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
    public function __construct(
        private readonly RiskManager $riskManager = new RiskManager,
        private readonly SimulatedTradeExecutor $executor = new SimulatedTradeExecutor,
    ) {}

    /**
     * @param  Candle[]  $candles  chronologically ordered (oldest first)
     */
    public function process(ActiveStrategy $active, array $candles): void
    {
        $strategy = StrategyFactory::fromModel($active->strategy);
        $signal = $strategy->generate($candles);
        $currentPrice = $candles[array_key_last($candles)]->close;
        $asset = $this->resolveAsset($active->symbol);
        $cycle = $this->resolveCycle($active);

        $this->recordEvent($active, $cycle, 'candle_processed', $asset->symbol, "Signal {$signal->type->value}: {$signal->reason}", [
            'signal' => $signal->type->value,
            'price' => $currentPrice,
        ]);

        match ($signal->type) {
            SignalType::HOLD => null,
            SignalType::BUY => $this->handleBuy($active, $cycle, $asset, $signal, $currentPrice),
            SignalType::SELL => $this->handleSell($active, $cycle, $asset, $signal, $currentPrice),
        };
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

        $assessment = $this->riskManager->evaluate($signal, $active->capital, $active->capital);

        if (! $assessment->allowed) {
            $this->recordEvent($active, $cycle, 'signal_rejected_by_risk', $asset->symbol, $assessment->reason);

            return;
        }

        $execution = $this->executor->buy($asset->symbol, $currentPrice, $active->capital);

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

    private function handleSell(ActiveStrategy $active, ?ActiveTradingCycleModel $cycle, Asset $asset, Signal $signal, string $currentPrice): void
    {
        $trade = $this->openTrade($active, $asset);

        if ($trade === null) {
            $this->recordEvent($active, $cycle, 'signal_ignored_no_open_position', $asset->symbol,
                "SELL ignored: no open position for {$asset->symbol}.");

            return;
        }

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
