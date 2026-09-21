<?php

namespace App\Models;

use App\Actions\Strategy\RunAutomaticSearchAction;
use Database\Factories\AutomaticSearchCycleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Append-only history of one completed "Modo Automático" search cycle,
 * persisted by {@see RunAutomaticSearchAction} as a consequence of the
 * pipeline result it already computed — never a second evaluation. Unlike
 * {@see AutomaticSearchState::$last_cycle} (overwritten on every cycle), every
 * row here is kept, so past cycles can be queried with SQL.
 */
#[Fillable(['account_id', 'started_at', 'completed_at', 'assets_reviewed', 'candidates_found'])]
class AutomaticSearchCycle extends Model
{
    /** @use HasFactory<AutomaticSearchCycleFactory> */
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
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Get the trading account this cycle belongs to.
     *
     * @return BelongsTo<TradingAccount, $this>
     */
    public function tradingAccount(): BelongsTo
    {
        return $this->belongsTo(TradingAccount::class, 'account_id');
    }

    /**
     * Get the assets reviewed during this cycle.
     *
     * @return HasMany<AutomaticSearchCycleAsset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(AutomaticSearchCycleAsset::class);
    }
}
