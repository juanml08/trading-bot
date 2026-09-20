<?php

namespace Database\Factories;

use App\Models\AutomaticSearchState;
use App\Models\TradingAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutomaticSearchState>
 */
class AutomaticSearchStateFactory extends Factory
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
            'symbol' => 'BTCUSDT',
            'timeframe' => '1h',
            'capital' => '500',
            'mode' => 'trial',
            'status' => AutomaticSearchState::STATUS_RUNNING,
        ];
    }
}
