<?php

namespace Tests\Unit;

use App\Models\ActiveStrategy;
use App\Models\ActiveTradingCycle;
use App\Models\Asset;
use App\Models\AutomaticSearchCycle;
use App\Models\Strategy as StrategyModel;
use App\Models\TradingAccount;
use App\Trading\ActiveTradingCycleState;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the {@see ActiveTradingCycle} model introduced to represent the
 * live trading cycle for a selected (account, asset, strategy) opportunity,
 * separate from {@see AutomaticSearchCycle} (a search-attempt
 * history record). This phase only models the entity and its state — no
 * transition logic, no 5-cycle limit, no expiration behaviour is exercised
 * here.
 */
class ActiveTradingCycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_state_enum_contains_exactly_the_defined_states(): void
    {
        $this->assertSame(
            ['HOLD', 'POSITION_OPEN', 'CLOSED', 'EXPIRED'],
            array_map(fn (ActiveTradingCycleState $state): string => $state->value, ActiveTradingCycleState::cases()),
        );
    }

    public function test_a_valid_active_trading_cycle_can_be_created(): void
    {
        $account = TradingAccount::factory()->create();
        $asset = Asset::factory()->create(['symbol' => 'BTCUSDT']);
        $strategy = StrategyModel::factory()->create();

        $cycle = ActiveTradingCycle::query()->create([
            'account_id' => $account->id,
            'asset_id' => $asset->id,
            'strategy_id' => $strategy->id,
            'state' => ActiveTradingCycleState::Hold,
            'started_at' => now(),
        ]);

        $this->assertDatabaseHas('active_trading_cycles', [
            'id' => $cycle->id,
            'account_id' => $account->id,
            'asset_id' => $asset->id,
            'strategy_id' => $strategy->id,
            'active_strategy_id' => null,
            'state' => 'HOLD',
        ]);
    }

    public function test_the_state_column_is_cast_to_the_state_enum(): void
    {
        $cycle = ActiveTradingCycle::factory()->create(['state' => ActiveTradingCycleState::PositionOpen]);

        $this->assertInstanceOf(ActiveTradingCycleState::class, $cycle->fresh()->state);
        $this->assertSame(ActiveTradingCycleState::PositionOpen, $cycle->fresh()->state);
    }

    public function test_started_at_and_expires_at_are_cast_to_datetime(): void
    {
        $cycle = ActiveTradingCycle::factory()->create([
            'started_at' => '2026-09-21 10:00:00',
            'expires_at' => '2026-09-21 16:00:00',
        ]);

        $fresh = $cycle->fresh();

        $this->assertInstanceOf(Carbon::class, $fresh->started_at);
        $this->assertInstanceOf(Carbon::class, $fresh->expires_at);
        $this->assertSame('2026-09-21 10:00:00', $fresh->started_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-21 16:00:00', $fresh->expires_at->format('Y-m-d H:i:s'));
    }

    public function test_expires_at_may_be_null_since_expiration_is_not_implemented_yet(): void
    {
        $cycle = ActiveTradingCycle::factory()->create(['expires_at' => null]);

        $this->assertNull($cycle->fresh()->expires_at);
    }

    public function test_it_belongs_to_the_expected_trading_account(): void
    {
        $account = TradingAccount::factory()->create();
        $cycle = ActiveTradingCycle::factory()->create(['account_id' => $account->id]);

        $this->assertTrue($cycle->tradingAccount->is($account));
        $this->assertTrue($account->activeTradingCycles->contains($cycle));
    }

    public function test_it_belongs_to_the_expected_asset(): void
    {
        $asset = Asset::factory()->create();
        $cycle = ActiveTradingCycle::factory()->create(['asset_id' => $asset->id]);

        $this->assertTrue($cycle->asset->is($asset));
        $this->assertTrue($asset->activeTradingCycles->contains($cycle));
    }

    public function test_it_belongs_to_the_expected_strategy(): void
    {
        $strategy = StrategyModel::factory()->create();
        $cycle = ActiveTradingCycle::factory()->create(['strategy_id' => $strategy->id]);

        $this->assertTrue($cycle->strategy->is($strategy));
        $this->assertTrue($strategy->activeTradingCycles->contains($cycle));
    }

    public function test_it_can_optionally_belong_to_an_active_strategy(): void
    {
        $activeStrategy = ActiveStrategy::factory()->create();
        $cycle = ActiveTradingCycle::factory()->create(['active_strategy_id' => $activeStrategy->id]);

        $this->assertTrue($cycle->activeStrategy->is($activeStrategy));
        $this->assertTrue($activeStrategy->activeTradingCycles->contains($cycle));
    }

    public function test_the_active_strategy_link_is_optional(): void
    {
        $cycle = ActiveTradingCycle::factory()->create(['active_strategy_id' => null]);

        $this->assertNull($cycle->fresh()->activeStrategy);
    }

    public function test_it_cannot_be_created_for_a_nonexistent_account(): void
    {
        $this->expectException(QueryException::class);

        ActiveTradingCycle::factory()->create(['account_id' => 999999]);
    }

    public function test_it_cannot_be_created_for_a_nonexistent_asset(): void
    {
        $this->expectException(QueryException::class);

        ActiveTradingCycle::factory()->create(['asset_id' => 999999]);
    }

    public function test_it_cannot_be_created_for_a_nonexistent_strategy(): void
    {
        $this->expectException(QueryException::class);

        ActiveTradingCycle::factory()->create(['strategy_id' => 999999]);
    }

    public function test_deleting_the_trading_account_deletes_its_active_trading_cycles(): void
    {
        $account = TradingAccount::factory()->create();
        $cycle = ActiveTradingCycle::factory()->create(['account_id' => $account->id]);

        $account->delete();

        $this->assertDatabaseMissing('active_trading_cycles', ['id' => $cycle->id]);
    }

    public function test_deleting_the_linked_active_strategy_does_not_delete_the_cycle(): void
    {
        $activeStrategy = ActiveStrategy::factory()->create();
        $cycle = ActiveTradingCycle::factory()->create(['active_strategy_id' => $activeStrategy->id]);

        $activeStrategy->delete();

        $this->assertDatabaseHas('active_trading_cycles', ['id' => $cycle->id, 'active_strategy_id' => null]);
    }
}
