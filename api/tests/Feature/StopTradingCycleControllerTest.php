<?php

namespace Tests\Feature;

use App\Models\ActiveTradingCycle;
use App\Models\Asset;
use App\Models\TradingAccount;
use App\Trading\ActiveTradingCycleState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers Fase 4A #3: POST /api/cycles/{cycle}/stop.
 */
class StopTradingCycleControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stops_the_given_cycle(): void
    {
        $account = TradingAccount::current();
        $cycle = ActiveTradingCycle::factory()->create([
            'account_id' => $account->id,
            'asset_id' => Asset::factory()->create(['symbol' => 'BNBUSDT'])->id,
            'state' => ActiveTradingCycleState::Hold,
        ]);

        $response = $this->postJson("/api/cycles/{$cycle->id}/stop");

        $response->assertOk();
        $this->assertSame('CLOSED', $response->json('status'));
        $this->assertSame(ActiveTradingCycleState::Closed, $cycle->fresh()->state);
    }

    public function test_it_returns_404_for_a_nonexistent_cycle(): void
    {
        TradingAccount::current();

        $response = $this->postJson('/api/cycles/999999/stop');

        $response->assertNotFound();
    }

    public function test_it_returns_422_for_an_already_closed_cycle(): void
    {
        $account = TradingAccount::current();
        $cycle = ActiveTradingCycle::factory()->create([
            'account_id' => $account->id,
            'asset_id' => Asset::factory()->create(['symbol' => 'BNBUSDT'])->id,
            'state' => ActiveTradingCycleState::Closed,
        ]);

        $response = $this->postJson("/api/cycles/{$cycle->id}/stop");

        $response->assertStatus(422);
    }

    public function test_stopping_one_cycle_does_not_affect_another(): void
    {
        $account = TradingAccount::current();
        $target = ActiveTradingCycle::factory()->create([
            'account_id' => $account->id,
            'asset_id' => Asset::factory()->create(['symbol' => 'BNBUSDT'])->id,
            'state' => ActiveTradingCycleState::Hold,
        ]);
        $other = ActiveTradingCycle::factory()->create([
            'account_id' => $account->id,
            'asset_id' => Asset::factory()->create(['symbol' => 'ADAUSDT'])->id,
            'state' => ActiveTradingCycleState::Hold,
        ]);

        $this->postJson("/api/cycles/{$target->id}/stop")->assertOk();

        $this->assertSame(ActiveTradingCycleState::Hold, $other->fresh()->state);
    }

    /**
     * Fase 4.5 audit item #13: an account must never be able to stop another
     * account's cycle. The controller scopes its lookup to
     * TradingAccount::current(), so a foreign cycle id is treated as
     * nonexistent (404), not found-but-forbidden.
     */
    public function test_it_cannot_stop_another_accounts_cycle(): void
    {
        TradingAccount::current();
        $otherAccount = TradingAccount::factory()->create();
        $otherCycle = ActiveTradingCycle::factory()->create([
            'account_id' => $otherAccount->id,
            'asset_id' => Asset::factory()->create(['symbol' => 'ADAUSDT'])->id,
            'state' => ActiveTradingCycleState::Hold,
        ]);

        $response = $this->postJson("/api/cycles/{$otherCycle->id}/stop");

        $response->assertNotFound();
        $this->assertSame(ActiveTradingCycleState::Hold, $otherCycle->fresh()->state);
    }
}
