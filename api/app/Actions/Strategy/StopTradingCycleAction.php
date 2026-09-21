<?php

namespace App\Actions\Strategy;

use App\Models\ActiveTradingCycle;
use App\Models\BotEvent;
use App\Trading\ActiveTradingCycleState;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Application-level use case for Fase 4A #3: stops one specific
 * {@see ActiveTradingCycle} without touching any other cycle. Both HOLD and
 * POSITION_OPEN may be stopped this way (both allow a direct transition to
 * CLOSED — see {@see ActiveTradingCycle}'s docblock); CLOSED and EXPIRED are
 * terminal and refuse a second stop.
 *
 * Mirrors {@see StopAutomaticModeAction}'s approach (state update + BotEvent)
 * but scoped to this one cycle's own {@see ActiveStrategy}, not the
 * account's "latest" one — stopping one cycle must never affect a sibling
 * cycle's still-running strategy.
 */
final readonly class StopTradingCycleAction
{
    public function __invoke(ActiveTradingCycle $cycle): ActiveTradingCycle
    {
        if (! in_array($cycle->state, [ActiveTradingCycleState::Hold, ActiveTradingCycleState::PositionOpen], true)) {
            throw new InvalidArgumentException('El ciclo ya está cerrado.');
        }

        return DB::transaction(function () use ($cycle): ActiveTradingCycle {
            $cycle->update(['state' => ActiveTradingCycleState::Closed]);

            $cycle->activeStrategy?->stopIfRunning();

            BotEvent::query()->create([
                'account_id' => $cycle->account_id,
                'active_trading_cycle_id' => $cycle->id,
                'event_type' => 'cycle_stopped',
                'asset' => $cycle->asset?->symbol,
                'message' => 'Ciclo detenido manualmente por el usuario.',
            ]);

            return $cycle->fresh();
        });
    }
}
