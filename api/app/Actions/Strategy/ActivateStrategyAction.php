<?php

namespace App\Actions\Strategy;

use App\MarketData\Timeframe;
use App\Models\ActiveStrategy;
use App\Models\Strategy as StrategyModel;
use App\Models\TradingAccount;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Application-level use case for "Aplicar": makes an already-searched
 * strategy the active one for an account, for a given symbol.
 *
 * Applying a new strategy stops whatever the account currently has applied
 * or running for that same symbol first — only one strategy can be active
 * per account+symbol at a time, so a fresh "Aplicar" for a symbol always
 * replaces that symbol's previous strategy rather than accumulating rows
 * silently. This is scoped to the symbol (not the whole account) so that
 * {@see ActiveTradingCycle}s for different symbols can stay active
 * simultaneously — see RunAutomaticSearchAction, which activates up to
 * `MAX_ACTIVE_CYCLES` independent (symbol, strategy) cycles per search.
 */
final readonly class ActivateStrategyAction
{
    public function __invoke(
        TradingAccount $account,
        string $strategyName,
        string $symbol,
        Timeframe $timeframe,
        string $capital,
        string $mode,
    ): ActiveStrategy {
        $strategy = StrategyModel::query()->where('name', $strategyName)->first();

        if ($strategy === null) {
            throw new InvalidArgumentException("Strategy \"{$strategyName}\" does not exist or was not selected by a search.");
        }

        $account->activeStrategies()
            ->where('symbol', strtoupper($symbol))
            ->whereIn('status', [ActiveStrategy::STATUS_APPLIED, ActiveStrategy::STATUS_RUNNING])
            ->update(['status' => ActiveStrategy::STATUS_STOPPED, 'stopped_at' => CarbonImmutable::now()]);

        return $account->activeStrategies()->create([
            'strategy_id' => $strategy->id,
            'symbol' => strtoupper($symbol),
            'timeframe' => $timeframe->value,
            'capital' => $capital,
            'mode' => $mode,
            'status' => ActiveStrategy::STATUS_APPLIED,
            'started_at' => CarbonImmutable::now(),
        ]);
    }
}
