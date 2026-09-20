<?php

namespace Database\Factories;

use App\Models\BotEvent;
use App\Models\TradingAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BotEvent>
 */
class BotEventFactory extends Factory
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
            'event_type' => 'candle_processed',
            'asset' => 'BTCUSDT',
            'message' => 'Signal HOLD: no crossover.',
        ];
    }
}
