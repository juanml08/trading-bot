<?php

namespace Tests\Unit;

use App\Actions\Strategy\StopTradingCycleAction;
use App\Models\ActiveStrategy;
use App\Models\ActiveTradingCycle;
use App\Models\Asset;
use App\Trading\ActiveTradingCycleState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Covers Fase 4A #3: {@see StopTradingCycleAction} closes exactly one cycle,
 * stops the ActiveStrategy it is paired with, logs a `cycle_stopped`
 * BotEvent, and refuses to touch a cycle that is already terminal.
 */
class StopTradingCycleActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_closes_a_hold_cycle(): void
    {
        $cycle = ActiveTradingCycle::factory()->create(['state' => ActiveTradingCycleState::Hold]);

        $stopped = (new StopTradingCycleAction)($cycle);

        $this->assertSame(ActiveTradingCycleState::Closed, $stopped->state);
        $this->assertSame(ActiveTradingCycleState::Closed, $cycle->fresh()->state);
    }

    public function test_it_closes_a_position_open_cycle(): void
    {
        $cycle = ActiveTradingCycle::factory()->create(['state' => ActiveTradingCycleState::PositionOpen]);

        (new StopTradingCycleAction)($cycle);

        $this->assertSame(ActiveTradingCycleState::Closed, $cycle->fresh()->state);
    }

    public function test_it_stops_the_linked_active_strategy(): void
    {
        $activeStrategy = ActiveStrategy::factory()->create(['status' => ActiveStrategy::STATUS_RUNNING]);
        $cycle = ActiveTradingCycle::factory()->create([
            'active_strategy_id' => $activeStrategy->id,
            'state' => ActiveTradingCycleState::Hold,
        ]);

        (new StopTradingCycleAction)($cycle);

        $fresh = $activeStrategy->fresh();
        $this->assertSame(ActiveStrategy::STATUS_STOPPED, $fresh->status);
        $this->assertNotNull($fresh->stopped_at);
    }

    public function test_it_does_not_affect_a_sibling_cycles_active_strategy(): void
    {
        $stoppedTarget = ActiveStrategy::factory()->create(['status' => ActiveStrategy::STATUS_RUNNING]);
        $sibling = ActiveStrategy::factory()->create(['status' => ActiveStrategy::STATUS_RUNNING]);
        $cycle = ActiveTradingCycle::factory()->create([
            'asset_id' => Asset::factory()->create(['symbol' => 'BNBUSDT'])->id,
            'active_strategy_id' => $stoppedTarget->id,
            'state' => ActiveTradingCycleState::Hold,
        ]);
        ActiveTradingCycle::factory()->create([
            'asset_id' => Asset::factory()->create(['symbol' => 'ADAUSDT'])->id,
            'active_strategy_id' => $sibling->id,
            'state' => ActiveTradingCycleState::Hold,
        ]);

        (new StopTradingCycleAction)($cycle);

        $this->assertSame(ActiveStrategy::STATUS_RUNNING, $sibling->fresh()->status);
    }

    public function test_it_logs_a_cycle_stopped_bot_event(): void
    {
        $cycle = ActiveTradingCycle::factory()->create(['state' => ActiveTradingCycleState::Hold]);

        (new StopTradingCycleAction)($cycle);

        $this->assertDatabaseHas('bot_events', [
            'account_id' => $cycle->account_id,
            'active_trading_cycle_id' => $cycle->id,
            'event_type' => 'cycle_stopped',
        ]);
    }

    public function test_it_refuses_to_stop_an_already_closed_cycle(): void
    {
        $cycle = ActiveTradingCycle::factory()->create(['state' => ActiveTradingCycleState::Closed]);

        $this->expectException(InvalidArgumentException::class);

        (new StopTradingCycleAction)($cycle);
    }

    public function test_it_refuses_to_stop_an_expired_cycle(): void
    {
        $cycle = ActiveTradingCycle::factory()->create(['state' => ActiveTradingCycleState::Expired]);

        $this->expectException(InvalidArgumentException::class);

        (new StopTradingCycleAction)($cycle);
    }
}
