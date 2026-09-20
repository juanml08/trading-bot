<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['strategy_id', 'rule_type', 'indicator', 'operator', 'value', 'logical_operator', 'rule_order'])]
class StrategyRule extends Model
{
    const UPDATED_AT = null;

    /**
     * Get the strategy that owns the rule.
     *
     * @return BelongsTo<Strategy, $this>
     */
    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class, 'strategy_id');
    }
}
