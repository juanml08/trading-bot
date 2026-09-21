<?php

namespace App\Actions\Strategy;

use App\MarketData\Timeframe;
use App\Models\ActiveTradingCycle;
use App\Models\Asset;
use App\Models\TradingAccount;
use App\Trading\ActiveTradingCycleState;
use Illuminate\Support\Facades\DB;

/**
 * Application-level use case that connects a selected (account, asset,
 * strategy) opportunity to the live trading flow: activates the
 * {@see App\Models\ActiveStrategy} that actually executes it (via
 * {@see ActivateStrategyAction}, unchanged) and creates the
 * {@see ActiveTradingCycle} that represents it, linked via
 * `active_strategy_id`. Both writes happen inside a single transaction —
 * there is no acceptable partial outcome (a cycle without its strategy, or
 * a strategy without its cycle), and neither write has a transaction of its
 * own to reuse, so this is the single transaction boundary for both.
 *
 * The cycle always starts in {@see ActiveTradingCycleState::Hold}, with
 * `expires_at` set to `started_at` plus
 * `config('trading.active_cycles.hold_timeout_hours')` — see
 * {@see ExpireHoldCyclesAction}, which moves a cycle still in HOLD past that
 * deadline to {@see ActiveTradingCycleState::Expired}. BUY, POSITION_OPEN,
 * SELL and CLOSED transitions are not implemented yet (a later phase drives
 * those from real market signals).
 */
final readonly class ActivateTradingCycleAction
{
    public function __construct(
        private ActivateStrategyAction $activateStrategyAction,
    ) {}

    public function __invoke(
        TradingAccount $account,
        string $strategyName,
        string $symbol,
        Timeframe $timeframe,
        string $capital,
        string $mode,
    ): ActiveTradingCycle {
        return DB::transaction(function () use ($account, $strategyName, $symbol, $timeframe, $capital, $mode): ActiveTradingCycle {
            $asset = $this->resolveAsset($symbol);

            $activeStrategy = ($this->activateStrategyAction)(
                $account,
                $strategyName,
                $symbol,
                $timeframe,
                $capital,
                $mode,
            );

            $startedAt = now();

            return ActiveTradingCycle::query()->create([
                'account_id' => $account->id,
                'asset_id' => $asset->id,
                'strategy_id' => $activeStrategy->strategy_id,
                'active_strategy_id' => $activeStrategy->id,
                'state' => ActiveTradingCycleState::Hold,
                'started_at' => $startedAt,
                'expires_at' => $startedAt->copy()->addHours((int) config('trading.active_cycles.hold_timeout_hours')),
            ]);
        });
    }

    /**
     * Mirrors {@see App\Automation\AutomaticTradingCycle}'s own asset
     * resolution: best-effort split of a symbol like "BTCUSDT" into
     * base/quote by testing known quote suffixes, falling back to the full
     * symbol as base and "UNKNOWN" as quote for an unrecognized one.
     */
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
