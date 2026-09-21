<?php

namespace Tests\Feature;

use App\Models\ActiveTradingCycle;
use App\Models\Asset;
use App\Models\Trade;
use App\Models\TradingAccount;
use App\Trading\ActiveTradingCycleState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers Fase 4A #5: GET /api/cycles/summary.
 */
class TradingCyclesSummaryControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['trading.active_cycles.max_active' => 5]);
    }

    public function test_it_reports_zeroed_metrics_with_no_activity(): void
    {
        TradingAccount::current();

        $response = $this->getJson('/api/cycles/summary');

        $response->assertOk()->assertJson([
            'active_cycles' => 0,
            'free_slots' => 5,
            'open_positions' => 0,
            'expired_today' => 0,
            'closed_trades_today' => 0,
        ]);
        $this->assertSame('0', $response->json('today_profit_loss'));
    }

    public function test_active_cycles_counts_hold_and_position_open_only(): void
    {
        $account = TradingAccount::current();
        $this->cycle($account, 'BNBUSDT', ActiveTradingCycleState::Hold);
        $this->cycle($account, 'XRPUSDT', ActiveTradingCycleState::PositionOpen);
        $this->cycle($account, 'ADAUSDT', ActiveTradingCycleState::Closed);
        $this->cycle($account, 'LTCUSDT', ActiveTradingCycleState::Expired);

        $response = $this->getJson('/api/cycles/summary');

        $response->assertOk()->assertJson(['active_cycles' => 2, 'free_slots' => 3, 'open_positions' => 1]);
    }

    public function test_expired_today_only_counts_cycles_expired_since_midnight(): void
    {
        $account = TradingAccount::current();
        $expiredToday = $this->cycle($account, 'BNBUSDT', ActiveTradingCycleState::Expired);
        $expiredToday->forceFill(['updated_at' => now()])->save();

        // `updated_at` isn't in ActiveTradingCycle's Fillable list, so
        // update() would silently drop it and the automatic timestamp would
        // win — forceFill() is required to make it stick for this test.
        $expiredYesterday = $this->cycle($account, 'XRPUSDT', ActiveTradingCycleState::Expired);
        $expiredYesterday->forceFill(['updated_at' => now()->subDay()])->save();

        $response = $this->getJson('/api/cycles/summary');

        $response->assertOk()->assertJson(['expired_today' => 1]);
    }

    public function test_closed_trades_today_and_todays_pl_sum_only_trades_closed_today(): void
    {
        $account = TradingAccount::current();
        $asset = Asset::factory()->create(['symbol' => 'BNBUSDT']);

        Trade::factory()->create([
            'account_id' => $account->id,
            'asset_id' => $asset->id,
            'status' => 'closed',
            'closed_at' => now(),
            'profit_loss' => '15.50',
        ]);
        Trade::factory()->create([
            'account_id' => $account->id,
            'asset_id' => $asset->id,
            'status' => 'closed',
            'closed_at' => now(),
            'profit_loss' => '-5.25',
        ]);
        Trade::factory()->create([
            'account_id' => $account->id,
            'asset_id' => $asset->id,
            'status' => 'closed',
            'closed_at' => now()->subDay(),
            'profit_loss' => '100',
        ]);
        Trade::factory()->create([
            'account_id' => $account->id,
            'asset_id' => $asset->id,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $response = $this->getJson('/api/cycles/summary');

        $response->assertOk()->assertJson(['closed_trades_today' => 2]);
        $this->assertSame('10.25000000', $response->json('today_profit_loss'));
    }

    private function cycle(TradingAccount $account, string $symbol, ActiveTradingCycleState $state): ActiveTradingCycle
    {
        return ActiveTradingCycle::factory()->create([
            'account_id' => $account->id,
            'asset_id' => Asset::factory()->create(['symbol' => $symbol])->id,
            'state' => $state,
        ]);
    }
}
