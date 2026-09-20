<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\Strategy;
use App\Models\Trade;
use App\Models\TradingAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Trade>
 */
class TradeFactory extends Factory
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
            'asset_id' => Asset::factory(),
            'entry_price' => '100',
            'quantity' => '1',
            'capital_used' => '100',
            'status' => 'open',
            'opened_at' => now(),
        ];
    }

    /**
     * A trade that closed with the given profit or loss.
     */
    public function closed(string $profitLoss = '0'): static
    {
        return $this->state(fn (): array => [
            'exit_price' => '110',
            'profit_loss' => $profitLoss,
            'profit_loss_percent' => '10',
            'status' => 'closed',
            'closed_at' => now(),
        ]);
    }
}
