<?php

namespace Tests\Feature;

use App\Models\ActiveStrategy;
use App\Models\ActiveTradingCycle;
use App\Models\Asset;
use App\Models\BotEvent;
use App\Models\Trade;
use App\Models\TradingAccount;
use App\Trading\ActiveTradingCycleState;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The open position's "Máximo restante" countdown and unrealized P/L are
 * computed by the backend (the frontend never derives them): the countdown
 * from the trade's `opened_at` plus `trading.risk_exit.max_holding_hours`.
 */
class OpenPositionCountdownTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_position_open_cycle_counts_down_from_the_eight_hour_maximum(): void
    {
        config(['trading.risk_exit.max_holding_hours' => 8]);
        $this->positionOpenCycle(openedAt: now()->subHours(2)->subMinutes(28));

        $response = $this->getJson('/api/cycles');

        $remaining = $response->json('cycles.0.max_holding_remaining_seconds');
        $this->assertGreaterThan(5 * 3600 + 31 * 60, $remaining);
        $this->assertLessThanOrEqual(5 * 3600 + 32 * 60, $remaining);
        $this->assertSame('5h 32m', $response->json('cycles.0.max_holding_remaining_human'));
        $this->assertSame(0, $response->json('cycles.0.remaining_seconds'));
    }

    public function test_a_fresh_position_never_shows_more_than_the_maximum(): void
    {
        config(['trading.risk_exit.max_holding_hours' => 8]);
        $this->positionOpenCycle(openedAt: now());

        $this->assertLessThanOrEqual(8 * 3600, $this->getJson('/api/cycles')->json('cycles.0.max_holding_remaining_seconds'));
    }

    public function test_the_countdown_stops_at_zero_once_the_maximum_is_exceeded(): void
    {
        config(['trading.risk_exit.max_holding_hours' => 8]);
        $this->positionOpenCycle(openedAt: now()->subHours(9));

        $response = $this->getJson('/api/cycles');

        $this->assertSame(0, $response->json('cycles.0.max_holding_remaining_seconds'));
        $this->assertSame('0m', $response->json('cycles.0.max_holding_remaining_human'));
    }

    public function test_a_hold_cycle_has_no_max_holding_countdown(): void
    {
        $account = TradingAccount::current();
        ActiveTradingCycle::factory()->create([
            'account_id' => $account->id,
            'asset_id' => Asset::factory()->create(['symbol' => 'BNBUSDT'])->id,
            'state' => ActiveTradingCycleState::Hold,
        ]);

        $this->assertNull($this->getJson('/api/cycles')->json('cycles.0.max_holding_remaining_seconds'));
    }

    public function test_the_status_endpoint_exposes_the_deadline_and_an_approximate_unrealized_pl(): void
    {
        config(['trading.risk_exit.max_holding_hours' => 8]);
        $account = TradingAccount::current();
        $asset = Asset::factory()->create(['symbol' => 'HOTUSDT']);
        $openedAt = now()->subHour();
        Trade::factory()->create([
            'account_id' => $account->id,
            'asset_id' => $asset->id,
            'entry_price' => '0.05',
            'quantity' => '5000',
            'capital_used' => '250',
            'opened_at' => $openedAt,
        ]);
        BotEvent::query()->create([
            'account_id' => $account->id,
            'event_type' => 'candle_processed',
            'asset' => 'HOTUSDT',
            'message' => 'Signal HOLD',
            'data' => ['signal' => 'HOLD', 'price' => '0.05969'],
        ]);

        $position = $this->getJson('/api/automatic/status')->json('openPosition');

        $this->assertSame('0.05969', $position['currentPrice']);
        $this->assertSame('48.45000000', $position['unrealizedProfitLoss']);
        $this->assertSame(8, $position['maxHoldingHours']);
        $this->assertSame(
            $openedAt->copy()->addHours(8)->getTimestamp(),
            Carbon::parse($position['maxHoldingExpiresAt'])->getTimestamp(),
        );
    }

    public function test_the_status_endpoint_has_no_unrealized_pl_before_any_evaluation(): void
    {
        $account = TradingAccount::current();
        Trade::factory()->create(['account_id' => $account->id]);

        $position = $this->getJson('/api/automatic/status')->json('openPosition');

        $this->assertNull($position['currentPrice']);
        $this->assertNull($position['unrealizedProfitLoss']);
    }

    private function positionOpenCycle(CarbonInterface $openedAt): ActiveTradingCycle
    {
        $account = TradingAccount::current();
        $asset = Asset::factory()->create(['symbol' => 'BNBUSDT']);
        $active = ActiveStrategy::factory()->create(['account_id' => $account->id, 'status' => ActiveStrategy::STATUS_RUNNING]);
        Trade::factory()->create([
            'account_id' => $account->id,
            'asset_id' => $asset->id,
            'active_strategy_id' => $active->id,
            'opened_at' => $openedAt,
        ]);

        return ActiveTradingCycle::factory()->create([
            'account_id' => $account->id,
            'asset_id' => $asset->id,
            'active_strategy_id' => $active->id,
            'state' => ActiveTradingCycleState::PositionOpen,
        ]);
    }
}
