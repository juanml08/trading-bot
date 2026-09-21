<?php

namespace Tests\Unit;

use App\Actions\Strategy\StopAllTradingCyclesAction;
use App\Actions\Strategy\StopTradingCycleAction;
use App\Models\ActiveTradingCycle;
use App\Models\Asset;
use App\Models\TradingAccount;
use App\Trading\ActiveTradingCycleState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers Fase 4A #4: {@see StopAllTradingCyclesAction} closes every
 * non-terminal cycle for the account (any mix of HOLD/POSITION_OPEN),
 * leaves other accounts' cycles untouched, and is a no-op when there is
 * nothing to stop.
 */
class StopAllTradingCyclesActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_closes_every_active_cycle_regardless_of_state(): void
    {
        $account = TradingAccount::factory()->create();
        $hold = $this->cycleFor($account, 'BNBUSDT', ActiveTradingCycleState::Hold);
        $positionOpen = $this->cycleFor($account, 'XRPUSDT', ActiveTradingCycleState::PositionOpen);

        $stopped = $this->action()($account);

        $this->assertSame(2, $stopped);
        $this->assertSame(ActiveTradingCycleState::Closed, $hold->fresh()->state);
        $this->assertSame(ActiveTradingCycleState::Closed, $positionOpen->fresh()->state);
    }

    public function test_it_does_not_touch_another_accounts_cycles(): void
    {
        $account = TradingAccount::factory()->create();
        $otherAccount = TradingAccount::factory()->create();
        $this->cycleFor($account, 'BNBUSDT', ActiveTradingCycleState::Hold);
        $untouched = $this->cycleFor($otherAccount, 'ADAUSDT', ActiveTradingCycleState::Hold);

        $this->action()($account);

        $this->assertSame(ActiveTradingCycleState::Hold, $untouched->fresh()->state);
    }

    public function test_it_does_not_touch_already_terminal_cycles(): void
    {
        $account = TradingAccount::factory()->create();
        $closed = $this->cycleFor($account, 'BNBUSDT', ActiveTradingCycleState::Closed);
        $expired = $this->cycleFor($account, 'ADAUSDT', ActiveTradingCycleState::Expired);

        $stopped = $this->action()($account);

        $this->assertSame(0, $stopped);
        $this->assertSame(ActiveTradingCycleState::Closed, $closed->fresh()->state);
        $this->assertSame(ActiveTradingCycleState::Expired, $expired->fresh()->state);
    }

    public function test_it_is_a_no_op_with_zero_cycles(): void
    {
        $account = TradingAccount::factory()->create();

        $stopped = $this->action()($account);

        $this->assertSame(0, $stopped);
        $this->assertDatabaseMissing('bot_events', ['event_type' => 'all_cycles_stopped']);
    }

    public function test_it_logs_a_summary_bot_event_when_it_stops_something(): void
    {
        $account = TradingAccount::factory()->create();
        $this->cycleFor($account, 'BNBUSDT', ActiveTradingCycleState::Hold);

        $this->action()($account);

        $this->assertDatabaseHas('bot_events', ['account_id' => $account->id, 'event_type' => 'all_cycles_stopped']);
    }

    private function cycleFor(TradingAccount $account, string $symbol, ActiveTradingCycleState $state): ActiveTradingCycle
    {
        return ActiveTradingCycle::factory()->create([
            'account_id' => $account->id,
            'asset_id' => Asset::factory()->create(['symbol' => $symbol])->id,
            'state' => $state,
        ]);
    }

    private function action(): StopAllTradingCyclesAction
    {
        return new StopAllTradingCyclesAction(new StopTradingCycleAction);
    }
}
