<?php

namespace App\Actions\Strategy;

use App\Models\ActiveStrategy;
use App\Models\ActiveTradingCycle;
use App\Models\BotEvent;
use App\Trading\ActiveTradingCycleState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Application-level use case for Fase 3's HOLD timeout: moves every
 * {@see ActiveTradingCycle} still in {@see ActiveTradingCycleState::Hold}
 * whose `expires_at` has passed to {@see ActiveTradingCycleState::Expired}.
 *
 * Only ever reads/writes HOLD cycles — a cycle in
 * {@see ActiveTradingCycleState::PositionOpen} has no `expires_at` deadline
 * to enforce here and is left untouched. Rows are never deleted, only their
 * `state` changes, so the full history stays queryable.
 *
 * Expiring a cycle immediately frees its slot: {@see ActiveTradingCycle}'s
 * `active()` scope (what `RunAutomaticSearchAction::availableSlots()` counts
 * against `config('trading.active_cycles.max_active')`) only matches
 * HOLD/POSITION_OPEN, so an EXPIRED cycle stops counting the moment this
 * action commits — no separate "release" step is needed.
 *
 * Also stops the cycle's linked {@see ActiveStrategy} (see
 * {@see ActiveStrategy::stopIfRunning()}) — an expired cycle must
 * not leave its strategy `running` and being evaluated for a cycle that no
 * longer exists (Fase 4.5 audit finding).
 */
final readonly class ExpireHoldCyclesAction
{
    public function __invoke(): int
    {
        $now = CarbonImmutable::now();

        $expiredCycles = ActiveTradingCycle::query()
            ->where('state', ActiveTradingCycleState::Hold)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->with('asset')
            ->get();

        foreach ($expiredCycles as $cycle) {
            $this->expireOne($cycle, $now);
        }

        return $expiredCycles->count();
    }

    private function expireOne(ActiveTradingCycle $cycle, CarbonImmutable $now): void
    {
        DB::transaction(function () use ($cycle, $now): void {
            $hoursElapsed = $cycle->started_at->diffInHours($now);

            $cycle->update(['state' => ActiveTradingCycleState::Expired]);

            $cycle->activeStrategy?->stopIfRunning();

            BotEvent::query()->create([
                'account_id' => $cycle->account_id,
                'active_trading_cycle_id' => $cycle->id,
                'event_type' => 'cycle_expired',
                'asset' => $cycle->asset?->symbol,
                'message' => "Ciclo HOLD expirado tras {$hoursElapsed}h sin generar señal BUY.",
            ]);
        });
    }
}
