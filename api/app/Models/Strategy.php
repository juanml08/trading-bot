<?php

namespace App\Models;

use Database\Factories\StrategyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'version', 'class', 'parameters', 'is_active'])]
class Strategy extends Model
{
    /** @use HasFactory<StrategyFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'parameters' => 'array',
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
     * Get the active strategy assignments for this strategy.
     *
     * @return HasMany<ActiveStrategy, $this>
     */
    public function activeStrategies(): HasMany
    {
        return $this->hasMany(ActiveStrategy::class, 'strategy_id');
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

    /**
     * Get the active trading cycles using this strategy.
     *
     * @return HasMany<ActiveTradingCycle, $this>
     */
    public function activeTradingCycles(): HasMany
    {
        return $this->hasMany(ActiveTradingCycle::class, 'strategy_id');
    }
}
