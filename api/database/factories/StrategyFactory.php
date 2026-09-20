<?php

namespace Database\Factories;

use App\Models\Strategy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Strategy>
 */
class StrategyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->sentence(),
            'version' => '1.0',
            'class' => 'App\Strategy\EmaCrossoverStrategy',
            'parameters' => [],
            'is_active' => true,
        ];
    }
}
