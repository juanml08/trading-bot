<?php

namespace App\Models;

use App\Actions\Strategy\RunAutomaticSearchAction;
use App\Strategy\StrategyEvaluation;
use App\Strategy\StrategySelector;
use Database\Factories\AutomaticSearchCycleStrategyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One strategy evaluated for an {@see AutomaticSearchCycleAsset} — including
 * strategies discarded in Discovery or Validation, not only the one
 * {@see StrategySelector} picked. `status` mirrors
 * {@see RunAutomaticSearchAction::strategyStatus()}: `discarded_in_discovery`,
 * `discarded_in_validation`, `validated_not_selected`, or `selected`.
 *
 * `train_*` columns hold the TRAIN {@see StrategyEvaluation}
 * metrics (always present). `validation_*` columns hold the VALIDATION
 * metrics and are null for strategies that never reached Validation.
 * `failed_criteria` keeps both stages' failed criteria separately:
 * `{"discovery": [...], "validation": [...]}`.
 */
#[Fillable([
    'automatic_search_cycle_asset_id',
    'strategy_name',
    'status',
    'discovery_passed',
    'validation_passed',
    'failed_criteria',
    'train_total_trades',
    'train_winning_trades',
    'train_losing_trades',
    'train_win_rate',
    'train_profit_loss',
    'train_profit_loss_percentage',
    'train_max_drawdown_percentage',
    'train_profit_factor',
    'train_total_costs',
    'train_gross_profit_loss',
    'validation_total_trades',
    'validation_winning_trades',
    'validation_losing_trades',
    'validation_win_rate',
    'validation_profit_loss',
    'validation_profit_loss_percentage',
    'validation_max_drawdown_percentage',
    'validation_profit_factor',
    'validation_total_costs',
    'validation_gross_profit_loss',
])]
class AutomaticSearchCycleStrategy extends Model
{
    /** @use HasFactory<AutomaticSearchCycleStrategyFactory> */
    use HasFactory;

    const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'discovery_passed' => 'boolean',
            'validation_passed' => 'boolean',
            'failed_criteria' => 'array',
            'train_win_rate' => 'decimal:8',
            'train_profit_loss' => 'decimal:8',
            'train_profit_loss_percentage' => 'decimal:8',
            'train_max_drawdown_percentage' => 'decimal:8',
            'train_total_costs' => 'decimal:8',
            'train_gross_profit_loss' => 'decimal:8',
            'validation_win_rate' => 'decimal:8',
            'validation_profit_loss' => 'decimal:8',
            'validation_profit_loss_percentage' => 'decimal:8',
            'validation_max_drawdown_percentage' => 'decimal:8',
            'validation_total_costs' => 'decimal:8',
            'validation_gross_profit_loss' => 'decimal:8',
        ];
    }

    /**
     * Get the asset review this strategy evaluation belongs to.
     *
     * @return BelongsTo<AutomaticSearchCycleAsset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(AutomaticSearchCycleAsset::class, 'automatic_search_cycle_asset_id');
    }
}
