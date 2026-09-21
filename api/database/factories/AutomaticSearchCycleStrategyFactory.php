<?php

namespace Database\Factories;

use App\Models\AutomaticSearchCycleAsset;
use App\Models\AutomaticSearchCycleStrategy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutomaticSearchCycleStrategy>
 */
class AutomaticSearchCycleStrategyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'automatic_search_cycle_asset_id' => AutomaticSearchCycleAsset::factory(),
            'strategy_name' => 'Idle',
            'status' => 'discarded_in_discovery',
            'discovery_passed' => false,
            'validation_passed' => null,
            'failed_criteria' => ['discovery' => ['minimumTrades'], 'validation' => []],
            'train_total_trades' => 0,
            'train_winning_trades' => 0,
            'train_losing_trades' => 0,
            'train_win_rate' => '0',
            'train_profit_loss' => '0',
            'train_profit_loss_percentage' => '0',
            'train_max_drawdown_percentage' => '0',
            'train_profit_factor' => '0',
            'train_total_costs' => '0',
            'train_gross_profit_loss' => '0',
        ];
    }
}
