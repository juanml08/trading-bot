<?php

namespace Tests\Feature;

use App\Models\ActiveTradingCycle;
use App\Models\Asset;
use App\Models\BotEvent;
use App\Models\Strategy;
use App\Models\TradingAccount;
use App\Trading\ActiveTradingCyclePresenter;
use App\Trading\ActiveTradingCycleState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers Fase 4A #1: GET /api/cycles lists the current account's cycles,
 * fully pre-computed (status, remaining time, last event) — see
 * {@see ActiveTradingCyclePresenter}.
 */
class ActiveTradingCyclesControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_an_empty_list_with_zero_cycles(): void
    {
        TradingAccount::current();

        $response = $this->getJson('/api/cycles');

        $response->assertOk()->assertJson(['cycles' => []]);
    }

    public function test_it_returns_a_single_cycle(): void
    {
        $account = TradingAccount::current();
        $strategy = Strategy::factory()->create(['name' => 'SMA Fast']);
        ActiveTradingCycle::factory()->create([
            'account_id' => $account->id,
            'asset_id' => Asset::factory()->create(['symbol' => 'BNBUSDT'])->id,
            'strategy_id' => $strategy->id,
            'state' => ActiveTradingCycleState::Hold,
        ]);

        $response = $this->getJson('/api/cycles');

        $response->assertOk()->assertJsonCount(1, 'cycles');
        $this->assertSame('BNBUSDT', $response->json('cycles.0.symbol'));
        $this->assertSame('SMA Fast', $response->json('cycles.0.strategy_name'));
        $this->assertSame('HOLD', $response->json('cycles.0.status'));
    }

    public function test_it_returns_five_cycles(): void
    {
        $account = TradingAccount::current();
        foreach (['BNB', 'XRP', 'ADA', 'LTC', 'SOL'] as $index => $base) {
            ActiveTradingCycle::factory()->create([
                'account_id' => $account->id,
                'asset_id' => Asset::factory()->create(['symbol' => "{$base}USDT"])->id,
                'state' => ActiveTradingCycleState::Hold,
            ]);
        }

        $response = $this->getJson('/api/cycles');

        $response->assertOk()->assertJsonCount(5, 'cycles');
    }

    public function test_remaining_seconds_reflects_the_configured_timeout(): void
    {
        $account = TradingAccount::current();
        ActiveTradingCycle::factory()->create([
            'account_id' => $account->id,
            'asset_id' => Asset::factory()->create(['symbol' => 'BNBUSDT'])->id,
            'state' => ActiveTradingCycleState::Hold,
            'expires_at' => now()->addHours(2)->addMinutes(10),
        ]);

        $response = $this->getJson('/api/cycles');

        $remaining = $response->json('cycles.0.remaining_seconds');
        $this->assertGreaterThan(7700, $remaining);
        $this->assertLessThanOrEqual(7800, $remaining);
        $this->assertSame('2h 10m', $response->json('cycles.0.remaining_human'));
    }

    public function test_remaining_seconds_is_never_negative_for_a_cycle_past_its_deadline(): void
    {
        $account = TradingAccount::current();
        ActiveTradingCycle::factory()->create([
            'account_id' => $account->id,
            'asset_id' => Asset::factory()->create(['symbol' => 'BNBUSDT'])->id,
            'state' => ActiveTradingCycleState::Hold,
            'expires_at' => now()->subHour(),
        ]);

        $response = $this->getJson('/api/cycles');

        $this->assertSame(0, $response->json('cycles.0.remaining_seconds'));
        $this->assertSame('0m', $response->json('cycles.0.remaining_human'));
    }

    public function test_an_expired_cycle_shows_zero_remaining_time(): void
    {
        $account = TradingAccount::current();
        ActiveTradingCycle::factory()->create([
            'account_id' => $account->id,
            'asset_id' => Asset::factory()->create(['symbol' => 'BNBUSDT'])->id,
            'state' => ActiveTradingCycleState::Expired,
            'expires_at' => now()->subHour(),
        ]);

        $response = $this->getJson('/api/cycles');

        $this->assertSame(0, $response->json('cycles.0.remaining_seconds'));
    }

    public function test_it_includes_the_last_bot_event_for_the_cycle(): void
    {
        $account = TradingAccount::current();
        $cycle = ActiveTradingCycle::factory()->create([
            'account_id' => $account->id,
            'asset_id' => Asset::factory()->create(['symbol' => 'BNBUSDT'])->id,
            'state' => ActiveTradingCycleState::Hold,
        ]);
        BotEvent::query()->create([
            'account_id' => $account->id,
            'active_trading_cycle_id' => $cycle->id,
            'event_type' => 'candle_processed',
            'message' => 'Signal HOLD: no crossover',
        ]);

        $response = $this->getJson('/api/cycles');

        $this->assertSame('candle_processed', $response->json('cycles.0.last_event.event_type'));
    }
}
