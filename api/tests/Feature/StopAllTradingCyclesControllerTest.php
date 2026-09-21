<?php

namespace Tests\Feature;

use App\Models\ActiveTradingCycle;
use App\Models\Asset;
use App\Models\TradingAccount;
use App\Trading\ActiveTradingCycleState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers Fase 4A #4: POST /api/cycles/stop-all.
 */
class StopAllTradingCyclesControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stops_a_mix_of_hold_and_position_open_cycles(): void
    {
        $account = TradingAccount::current();
        $hold = ActiveTradingCycle::factory()->create([
            'account_id' => $account->id,
            'asset_id' => Asset::factory()->create(['symbol' => 'BNBUSDT'])->id,
            'state' => ActiveTradingCycleState::Hold,
        ]);
        $positionOpen = ActiveTradingCycle::factory()->create([
            'account_id' => $account->id,
            'asset_id' => Asset::factory()->create(['symbol' => 'XRPUSDT'])->id,
            'state' => ActiveTradingCycleState::PositionOpen,
        ]);

        $response = $this->postJson('/api/cycles/stop-all');

        $response->assertOk()->assertJson(['stopped' => 2]);
        $this->assertSame(ActiveTradingCycleState::Closed, $hold->fresh()->state);
        $this->assertSame(ActiveTradingCycleState::Closed, $positionOpen->fresh()->state);
    }

    public function test_it_returns_zero_with_no_active_cycles(): void
    {
        TradingAccount::current();

        $response = $this->postJson('/api/cycles/stop-all');

        $response->assertOk()->assertJson(['stopped' => 0]);
    }
}
