<?php

namespace Tests\Feature;

use App\Models\ActiveStrategy;
use App\Models\Strategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ActivateStrategyControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_activating_a_valid_strategy_persists_it_as_the_active_strategy(): void
    {
        $this->fakeBinance();
        Strategy::factory()->create(['name' => 'EMA Simple', 'class' => 'App\Strategy\EmaCrossoverStrategy', 'parameters' => []]);

        $response = $this->postJson('/api/strategies/activate', $this->payload());

        $response->assertOk();

        $this->assertDatabaseHas('active_strategies', [
            'symbol' => 'BTCUSDT',
            'timeframe' => '1h',
            'status' => ActiveStrategy::STATUS_APPLIED,
        ]);
    }

    public function test_it_rejects_a_strategy_that_was_never_searched_or_selected(): void
    {
        Http::fake();

        $response = $this->postJson('/api/strategies/activate', $this->payload(['strategy_name' => 'Not A Real Strategy']));

        $response->assertUnprocessable()->assertJsonValidationErrors('strategy_name');
        $this->assertDatabaseCount('active_strategies', 0);
    }

    public function test_it_rejects_mode_real(): void
    {
        Http::fake();
        Strategy::factory()->create(['name' => 'EMA Simple']);

        $response = $this->postJson('/api/strategies/activate', $this->payload(['mode' => 'real']));

        $response->assertUnprocessable()->assertJsonValidationErrors('mode');
        $this->assertDatabaseCount('active_strategies', 0);
    }

    public function test_it_rejects_capital_above_the_binance_demo_balance(): void
    {
        $this->fakeBinance(free: '10.00000000');
        Strategy::factory()->create(['name' => 'EMA Simple']);

        $response = $this->postJson('/api/strategies/activate', $this->payload(['capital' => '500']));

        $response->assertUnprocessable()->assertJsonValidationErrors('capital');
        $this->assertDatabaseCount('active_strategies', 0);
    }

    public function test_applying_a_new_strategy_stops_the_previously_applied_one(): void
    {
        $this->fakeBinance();
        Strategy::factory()->create(['name' => 'EMA Simple']);
        Strategy::factory()->create(['name' => 'SMA Fast']);

        $this->postJson('/api/strategies/activate', $this->payload(['strategy_name' => 'EMA Simple']))->assertOk();
        $this->postJson('/api/strategies/activate', $this->payload(['strategy_name' => 'SMA Fast']))->assertOk();

        $this->assertSame(1, ActiveStrategy::query()->where('status', ActiveStrategy::STATUS_APPLIED)->count());
        $this->assertSame(1, ActiveStrategy::query()->where('status', ActiveStrategy::STATUS_STOPPED)->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'strategy_name' => 'EMA Simple',
            'symbol' => 'BTCUSDT',
            'timeframe' => '1h',
            'capital' => '500',
            'mode' => 'trial',
        ], $overrides);
    }

    private function fakeBinance(string $free = '10000.00000000'): void
    {
        Http::fake([
            '*/api/v3/account*' => Http::response([
                'balances' => [
                    ['asset' => 'USDT', 'free' => $free, 'locked' => '0.00000000'],
                ],
            ], 200),
        ]);
    }
}
