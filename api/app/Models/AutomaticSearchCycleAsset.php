<?php

namespace App\Models;

use App\Actions\Strategy\RunAutomaticSearchAction;
use Database\Factories\AutomaticSearchCycleAssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One asset reviewed within an {@see AutomaticSearchCycle}, carrying the same
 * outcome {@see RunAutomaticSearchAction::reviewFor()} already computes:
 * `no_opportunity` (no strategy passed Discovery), `candidate_found`
 * (selected automatically), or `discarded` (reached Validation but was not
 * selected, or failed Validation).
 */
#[Fillable(['automatic_search_cycle_id', 'symbol', 'status', 'evaluated_at'])]
class AutomaticSearchCycleAsset extends Model
{
    /** @use HasFactory<AutomaticSearchCycleAssetFactory> */
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
            'evaluated_at' => 'datetime',
        ];
    }

    /**
     * Get the cycle this asset review belongs to.
     *
     * @return BelongsTo<AutomaticSearchCycle, $this>
     */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(AutomaticSearchCycle::class, 'automatic_search_cycle_id');
    }

    /**
     * Get every strategy evaluated for this asset.
     *
     * @return HasMany<AutomaticSearchCycleStrategy, $this>
     */
    public function strategies(): HasMany
    {
        return $this->hasMany(AutomaticSearchCycleStrategy::class);
    }
}
