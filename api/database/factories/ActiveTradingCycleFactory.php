<?php

namespace Database\Factories;

use App\Models\ActiveTradingCycle;
use App\Models\Asset;
use App\Models\Strategy;
use App\Models\TradingAccount;
use App\Trading\ActiveTradingCycleState;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActiveTradingCycle>
 */
class ActiveTradingCycleFactory extends Factory
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
            'asset_id' => Asset::factory(),
            'strategy_id' => Strategy::factory(),
            'active_strategy_id' => null,
            'state' => ActiveTradingCycleState::Hold,
            'started_at' => now(),
            'expires_at' => null,
        ];
    }
}
