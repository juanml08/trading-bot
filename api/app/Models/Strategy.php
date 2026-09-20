<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'version', 'is_active'])]
class Strategy extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the trades for the strategy.
     *
     * @return HasMany<Trade, $this>
     */
    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class, 'strategy_id');
    }

    /**
     * Get the rules for the strategy.
     *
     * @return HasMany<StrategyRule, $this>
     */
    public function rules(): HasMany
    {
        return $this->hasMany(StrategyRule::class, 'strategy_id');
    }
}
