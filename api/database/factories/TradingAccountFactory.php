<?php

namespace Database\Factories;

use App\Models\TradingAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TradingAccount>
 */
class TradingAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'exchange' => 'binance',
            'account_type' => 'paper',
            'is_active' => true,
        ];
    }
}
