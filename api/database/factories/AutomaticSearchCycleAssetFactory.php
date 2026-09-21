<?php

namespace Database\Factories;

use App\Models\AutomaticSearchCycle;
use App\Models\AutomaticSearchCycleAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutomaticSearchCycleAsset>
 */
class AutomaticSearchCycleAssetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'automatic_search_cycle_id' => AutomaticSearchCycle::factory(),
            'symbol' => 'BTCUSDT',
            'status' => 'no_opportunity',
            'evaluated_at' => now(),
        ];
    }
}
