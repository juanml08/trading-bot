<?php

namespace Database\Factories;

use App\Models\AutomaticSearchCycle;
use App\Models\TradingAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutomaticSearchCycle>
 */
class AutomaticSearchCycleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = $this->faker->dateTimeBetween('-1 day', 'now');

        return [
            'account_id' => TradingAccount::factory(),
            'started_at' => $startedAt,
            'completed_at' => $startedAt,
            'assets_reviewed' => 1,
            'candidates_found' => 0,
        ];
    }
}
