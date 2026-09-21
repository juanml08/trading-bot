<?php

namespace App\Actions\Strategy;

use App\Models\ActiveTradingCycle;
use App\Models\BotEvent;
use App\Models\TradingAccount;

/**
 * Application-level use case for Fase 4A #4: stops every one of the
 * account's non-terminal {@see ActiveTradingCycle}s (HOLD and
 * POSITION_OPEN alike), reusing {@see StopTradingCycleAction} per cycle so
 * the two actions can never disagree about what "stopping a cycle" does
 * (close it, stop its ActiveStrategy, log a BotEvent). A no-op (returns 0,
 * logs nothing extra) when the account has no active cycle.
 */
final readonly class StopAllTradingCyclesAction
{
    public function __construct(
        private StopTradingCycleAction $stopAction,
    ) {}

    public function __invoke(TradingAccount $account): int
    {
        $cycles = $account->activeTradingCycles()->active()->get();

        foreach ($cycles as $cycle) {
            ($this->stopAction)($cycle);
        }

        if ($cycles->isEmpty()) {
            return 0;
        }

        BotEvent::query()->create([
            'account_id' => $account->id,
            'event_type' => 'all_cycles_stopped',
            'asset' => null,
            'message' => "Se detuvieron {$cycles->count()} ciclo(s) manualmente.",
        ]);

        return $cycles->count();
    }
}
