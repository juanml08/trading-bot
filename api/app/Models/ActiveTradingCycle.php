<?php

namespace App\Models;

use App\Trading\ActiveTradingCyclePresenter;
use App\Trading\ActiveTradingCycleState;
use Database\Factories\ActiveTradingCycleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The live trading cycle for one selected (account, asset, strategy)
 * opportunity — distinct from {@see AutomaticSearchCycle}, which is an
 * append-only record of a search attempt, not a live state. This is the
 * single source of truth for a cycle's lifecycle; {@see ActiveStrategy}
 * only represents the strategy execution running underneath it.
 * `active_strategy_id` is nullable because that link may not exist yet.
 *
 * Allowed transitions (enforced by callers, not the model): Hold ->
 * PositionOpen/Closed/Expired (via {@see App\Automation\AutomaticTradingCycle}
 * on BUY, {@see App\Actions\Strategy\StopTradingCycleAction} on a manual
 * stop, and {@see App\Actions\Strategy\ExpireHoldCyclesAction} on timeout,
 * respectively), and PositionOpen -> Closed (via
 * {@see App\Automation\AutomaticTradingCycle} on SELL, or a manual stop).
 * Closed and Expired are terminal — nothing ever transitions a cycle back
 * out of them.
 */
#[Fillable(['account_id', 'asset_id', 'strategy_id', 'active_strategy_id', 'state', 'started_at', 'expires_at'])]
class ActiveTradingCycle extends Model
{
    /** @use HasFactory<ActiveTradingCycleFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => ActiveTradingCycleState::class,
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Scope to cycles that still count against the `MAX_ACTIVE_CYCLES` limit
     * (see `config('trading.active_cycles.max_active')`): HOLD and
     * POSITION_OPEN. CLOSED and EXPIRED are terminal and free their slot.
     *
     * @param  Builder<ActiveTradingCycle>  $query
     * @return Builder<ActiveTradingCycle>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('state', [
            ActiveTradingCycleState::Hold,
            ActiveTradingCycleState::PositionOpen,
        ]);
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
     * Get the asset being traded in this cycle.
     *
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    /**
     * Get the strategy selected for this cycle.
     *
     * @return BelongsTo<Strategy, $this>
     */
    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class, 'strategy_id');
    }

    /**
     * Get the active strategy assignment executing this cycle, if one has
     * been created yet.
     *
     * @return BelongsTo<ActiveStrategy, $this>
     */
    public function activeStrategy(): BelongsTo
    {
        return $this->belongsTo(ActiveStrategy::class, 'active_strategy_id');
    }

    /**
     * Get the bot events attributed to this cycle (see the
     * `active_trading_cycle_id` migration on {@see BotEvent}), used to build
     * its history timeline. Ordered latest-first by default so `->first()`
     * (used for "last event") is cheap and correct whether the caller loaded
     * every event or constrained the eager load to just one (see
     * {@see ActiveTradingCyclePresenter}).
     *
     * @return HasMany<BotEvent, $this>
     */
    public function botEvents(): HasMany
    {
        return $this->hasMany(BotEvent::class, 'active_trading_cycle_id')->latest('id');
    }
}
