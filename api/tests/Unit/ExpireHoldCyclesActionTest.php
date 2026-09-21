<?php

namespace Tests\Unit;

use App\Actions\Strategy\ExpireHoldCyclesAction;
use App\Models\ActiveStrategy;
use App\Models\ActiveTradingCycle;
use App\Models\Asset;
use App\Models\BotEvent;
use App\Models\TradingAccount;
use App\Trading\ActiveTradingCycleState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers Fase 3's HOLD timeout: {@see ExpireHoldCyclesAction} moves a cycle
 * still in HOLD past its `expires_at` deadline to EXPIRED, logs a
 * `cycle_expired` {@see BotEvent}, leaves POSITION_OPEN cycles untouched, and
 * — since {@see ActiveTradingCycle}'s `active()` scope only matches
 * HOLD/POSITION_OPEN — frees the expired cycle's slot immediately.
 */
class ExpireHoldCyclesActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_hold_cycle_past_its_deadline_expires(): void
    {
        $cycle = ActiveTradingCycle::factory()->create([
            'state' => ActiveTradingCycleState::Hold,
            'started_at' => now()->subHours(5),
            'expires_at' => now()->subHour(),
        ]);

        (new ExpireHoldCyclesAction)();

        $this->assertSame(ActiveTradingCycleState::Expired, $cycle->fresh()->state);
    }

    public function test_a_hold_cycle_not_yet_past_its_deadline_stays_on_hold(): void
    {
        $cycle = ActiveTradingCycle::factory()->create([
            'state' => ActiveTradingCycleState::Hold,
            'started_at' => now()->subHour(),
            'expires_at' => now()->addHours(3),
        ]);

        (new ExpireHoldCyclesAction)();

        $this->assertSame(ActiveTradingCycleState::Hold, $cycle->fresh()->state);
    }

    public function test_a_position_open_cycle_never_expires_regardless_of_expires_at(): void
    {
        $cycle = ActiveTradingCycle::factory()->create([
            'state' => ActiveTradingCycleState::PositionOpen,
            'started_at' => now()->subHours(10),
            'expires_at' => now()->subHours(5),
        ]);

        (new ExpireHoldCyclesAction)();

        $this->assertSame(ActiveTradingCycleState::PositionOpen, $cycle->fresh()->state);
    }

    public function test_a_hold_cycle_with_no_expires_at_is_left_alone(): void
    {
        $cycle = ActiveTradingCycle::factory()->create([
            'state' => ActiveTradingCycleState::Hold,
            'expires_at' => null,
        ]);

        (new ExpireHoldCyclesAction)();

        $this->assertSame(ActiveTradingCycleState::Hold, $cycle->fresh()->state);
    }

    public function test_it_returns_the_number_of_cycles_expired(): void
    {
        ActiveTradingCycle::factory()->create([
            'asset_id' => Asset::factory()->create(['symbol' => 'ONEUSDT'])->id,
            'state' => ActiveTradingCycleState::Hold,
            'expires_at' => now()->subMinute(),
        ]);
        ActiveTradingCycle::factory()->create([
            'asset_id' => Asset::factory()->create(['symbol' => 'TWOUSDT'])->id,
            'state' => ActiveTradingCycleState::Hold,
            'expires_at' => now()->subMinute(),
        ]);
        ActiveTradingCycle::factory()->create([
            'asset_id' => Asset::factory()->create(['symbol' => 'THREEUSDT'])->id,
            'state' => ActiveTradingCycleState::Hold,
            'expires_at' => now()->addHour(),
        ]);

        $expiredCount = (new ExpireHoldCyclesAction)();

        $this->assertSame(2, $expiredCount);
    }

    public function test_expiring_a_cycle_logs_a_cycle_expired_bot_event(): void
    {
        $asset = Asset::factory()->create(['symbol' => 'BNBUSDT']);
        $cycle = ActiveTradingCycle::factory()->create([
            'asset_id' => $asset->id,
            'state' => ActiveTradingCycleState::Hold,
            'started_at' => now()->subHours(4),
            'expires_at' => now()->subMinute(),
        ]);

        (new ExpireHoldCyclesAction)();

        $this->assertDatabaseHas('bot_events', [
            'account_id' => $cycle->account_id,
            'event_type' => 'cycle_expired',
            'asset' => 'BNBUSDT',
        ]);
    }

    /**
     * Fase 4.5 audit finding: an expired cycle must not leave its
     * ActiveStrategy `running` — otherwise the scheduler keeps evaluating a
     * strategy for a cycle that no longer exists, and a later BUY would open
     * an orphan Trade with no governing cycle.
     */
    public function test_expiring_a_cycle_stops_its_linked_active_strategy(): void
    {
        $activeStrategy = ActiveStrategy::factory()->create(['status' => ActiveStrategy::STATUS_RUNNING]);
        ActiveTradingCycle::factory()->create([
            'active_strategy_id' => $activeStrategy->id,
            'state' => ActiveTradingCycleState::Hold,
            'expires_at' => now()->subMinute(),
        ]);

        (new ExpireHoldCyclesAction)();

        $fresh = $activeStrategy->fresh();
        $this->assertSame(ActiveStrategy::STATUS_STOPPED, $fresh->status);
        $this->assertNotNull($fresh->stopped_at);
    }

    public function test_an_expired_cycle_immediately_frees_its_slot(): void
    {
        $accountId = TradingAccount::factory()->create()->id;
        ActiveTradingCycle::factory()->create([
            'account_id' => $accountId,
            'state' => ActiveTradingCycleState::Hold,
            'expires_at' => now()->subMinute(),
        ]);

        $this->assertSame(1, ActiveTradingCycle::query()->active()->where('account_id', $accountId)->count());

        (new ExpireHoldCyclesAction)();

        $this->assertSame(0, ActiveTradingCycle::query()->active()->where('account_id', $accountId)->count());
    }
}
