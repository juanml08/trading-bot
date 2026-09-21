<?php

namespace Database\Factories;

use App\Models\RiskSetting;
use App\Models\TradingAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RiskSetting>
 */
class RiskSettingFactory extends Factory
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
            'max_risk_per_trade' => '1.0000',
            'max_daily_loss' => '2.0000',
            'max_open_trades' => 1,
            'stop_loss_percent' => '1.0000',
            'take_profit_percent' => '2.0000',
            'max_capital_per_trade' => '20.00000000',
        ];
    }
}
