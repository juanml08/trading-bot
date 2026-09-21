<?php

namespace App\Trading;

use App\Actions\Strategy\ExpireHoldCyclesAction;
use App\Automation\AutomaticTradingCycle;
use App\Models\ActiveTradingCycle;
use App\Models\BotEvent;
use Carbon\CarbonImmutable;

/**
 * Fase 4: shapes an {@see ActiveTradingCycle} into the JSON contract the
 * multi-cycle Dashboard consumes, so the frontend never recomputes state or
 * time itself (see the Dashboard's own docs: "El Frontend no debe calcular
 * estados ni tiempos."). Pure presentation — it reads fields and relations
 * that are already computed/persisted elsewhere (the cycle's own state,
 * `ActiveStrategy::nextEvaluationAt()`, {@see BotEvent}) and
 * never mutates anything.
 */
final class ActiveTradingCyclePresenter
{
    /**
     * The list-row shape (Fase 4A #1) and the base of the detail shape
     * (Fase 4A #2). Expects `asset`, `strategy`, `activeStrategy`, and
     * `botEvents` (or at least its latest entry) to already be loaded by the
     * caller, so this never issues its own queries.
     *
     * @return array<string, mixed>
     */
    public static function summarize(ActiveTradingCycle $cycle): array
    {
        $remainingSeconds = self::remainingSeconds($cycle);
        $lastEvent = $cycle->botEvents->first();

        return [
            'id' => $cycle->id,
            'symbol' => $cycle->asset?->symbol,
            'strategy_name' => $cycle->strategy?->name,
            'status' => $cycle->state->value,
            'started_at' => $cycle->started_at,
            'expires_at' => $cycle->expires_at,
            'remaining_seconds' => $remainingSeconds,
            'remaining_human' => self::remainingHuman($remainingSeconds),
            'next_review_at' => $cycle->activeStrategy?->nextEvaluationAt(),
            'last_event' => $lastEvent === null ? null : [
                'event_type' => $lastEvent->event_type,
                'message' => $lastEvent->message,
                'created_at' => $lastEvent->created_at,
            ],
        ];
    }

    /**
     * Fase 4A #2: the list-row shape plus the cycle's full chronological
     * history (see `history()`).
     *
     * @return array<string, mixed>
     */
    public static function detail(ActiveTradingCycle $cycle): array
    {
        return [
            ...self::summarize($cycle),
            'history' => self::history($cycle),
        ];
    }

    /**
     * Fase 4A #6: seconds left in HOLD, never negative, and always 0 once
     * the cycle has left HOLD (POSITION_OPEN/CLOSED/EXPIRED) or never had a
     * deadline — `expires_at` only ever governs the HOLD timeout (see
     * {@see ExpireHoldCyclesAction}), so it has no
     * meaning once the cycle has moved on.
     */
    public static function remainingSeconds(ActiveTradingCycle $cycle): int
    {
        if ($cycle->state !== ActiveTradingCycleState::Hold || $cycle->expires_at === null) {
            return 0;
        }

        return max(0, $cycle->expires_at->getTimestamp() - CarbonImmutable::now()->getTimestamp());
    }

    /**
     * Formats a non-negative second count as "2h 10m", "3h", or "50m" — the
     * exact examples Fase 4A #6 asks for.
     */
    public static function remainingHuman(int $remainingSeconds): string
    {
        if ($remainingSeconds <= 0) {
            return '0m';
        }

        $hours = intdiv($remainingSeconds, 3600);
        $minutes = intdiv($remainingSeconds % 3600, 60);

        if ($hours === 0) {
            return "{$minutes}m";
        }

        return $minutes === 0 ? "{$hours}h" : "{$hours}h {$minutes}m";
    }

    /**
     * Fase 4A #7: the cycle's chronological history, built exclusively from
     * real, already-persisted sources — the cycle's own `started_at` and
     * every {@see BotEvent} attributed to it (via
     * `active_trading_cycle_id`, which is what makes this precise instead of
     * a guess by asset symbol — see that migration's docblock). No
     * synthetic/periodic entries (e.g. a recurring "still on HOLD" marker)
     * are invented.
     *
     * Ordered by each event's id, not by comparing `created_at` values:
     * `bot_events.created_at` has only second precision, so two events
     * created moments apart in the same request (e.g. `position_closed`
     * immediately followed by `cycle_closed` — see
     * {@see AutomaticTradingCycle::handleSell()}) can share
     * the exact same timestamp. Insertion order (the auto-increment id) is
     * what actually reflects when each one happened; `cycle_created` is
     * always first because a cycle must exist before anything can be
     * attributed to it.
     *
     * @return array<int, array{at: CarbonImmutable, type: string, message: string}>
     */
    public static function history(ActiveTradingCycle $cycle): array
    {
        $timeline = [
            [
                'at' => $cycle->started_at,
                'type' => 'cycle_created',
                'message' => 'Ciclo creado.',
            ],
        ];

        foreach ($cycle->botEvents->sortBy('id') as $event) {
            $timeline[] = [
                'at' => $event->created_at,
                'type' => $event->event_type,
                'message' => $event->message,
            ];
        }

        return $timeline;
    }
}
