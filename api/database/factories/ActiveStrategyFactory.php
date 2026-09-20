<?php

namespace Database\Factories;

use App\Models\ActiveStrategy;
use App\Models\Strategy;
use App\Models\TradingAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActiveStrategy>
 */
class ActiveStrategyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => TradingAccount::factory(),
            'strategy_id' => Strategy::factory(),
            'symbol' => 'BTCUSDT',
            'timeframe' => '1h',
            'capital' => '500',
            'mode' => 'trial',
            'status' => ActiveStrategy::STATUS_RUNNING,
            'started_at' => now(),
        ];
    }
}
