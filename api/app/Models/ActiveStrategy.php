<?php

namespace App\Models;

use App\Console\Commands\ProcessAutomaticTradingCommand;
use App\MarketData\Timeframe;
use Carbon\Carbon;
use Database\Factories\ActiveStrategyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Records, for a given trading account, which strategy is currently applied
 * for the automatic mode and its lifecycle: `applied` (selected via
 * "Aplicar" but not yet running), `running` (the scheduler is processing new
 * candles for it), or `stopped` (explicitly stopped by the user).
 *
 * This is the persisted counterpart of "estrategia activa": it lets the
 * automatic trading cycle survive a process restart by reading `symbol`,
 * `timeframe`, `capital` and the related {@see Strategy} back from the
 * database instead of keeping them in memory.
 */
#[Fillable(['account_id', 'strategy_id', 'symbol', 'timeframe', 'capital', 'mode', 'status', 'started_at', 'stopped_at', 'last_evaluated_at'])]
class ActiveStrategy extends Model
{
    /** @use HasFactory<ActiveStrategyFactory> */
    use HasFactory;

    public const string STATUS_APPLIED = 'applied';

    public const string STATUS_RUNNING = 'running';

    public const string STATUS_STOPPED = 'stopped';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capital' => 'decimal:8',
            'started_at' => 'datetime',
            'stopped_at' => 'datetime',
            'last_evaluated_at' => 'datetime',
        ];
    }

    /**
     * Get the trading account this strategy is active for.
     *
     * @return BelongsTo<TradingAccount, $this>
     */
    public function tradingAccount(): BelongsTo
    {
        return $this->belongsTo(TradingAccount::class, 'account_id');
    }

    /**
     * Get the strategy that is active.
     *
     * @return BelongsTo<Strategy, $this>
     */
    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class, 'strategy_id');
    }

    /**
     * Get the trades opened during this active-strategy assignment, i.e.
     * during the current automatic-mode "cycle".
     *
     * @return HasMany<Trade, $this>
     */
    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class, 'active_strategy_id');
    }

    /**
     * The next time a candle of this active strategy's timeframe is due to
     * be evaluated, or null if no candle has ever been evaluated yet (see
     * {@see ProcessAutomaticTradingCommand}).
     */
    public function nextEvaluationAt(): ?Carbon
    {
        if ($this->last_evaluated_at === null) {
            return null;
        }

        return $this->last_evaluated_at->copy()->addMinutes(Timeframe::from($this->timeframe)->intervalInMinutes());
    }
}
