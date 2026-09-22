<?php

namespace App\Models;

use App\Actions\Strategy\RunAutomaticSearchAction;
use App\Console\Commands\AutomaticStrategySearchCommand;
use App\Opportunity\OpportunityScanner;
use Carbon\CarbonImmutable;
use Database\Factories\AutomaticSearchStateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persists, per trading account, whether "Modo Automático" (autonomous
 * strategy selection) has been started, and when it should next retry
 * searching for a candidate. This is a different concept from
 * {@see ActiveStrategy}: it can exist and be `running` even while there is
 * no active strategy at all — that is exactly the "no candidate yet, waiting
 * for the next attempt" state.
 *
 * Search only happens while `status = running`, and keeps retrying on
 * `retry_seconds` regardless of whether the previous attempt activated a
 * candidate — activating one only fills one of up to
 * `config('trading.active_cycles.max_active')` {@see ActiveTradingCycle}
 * slots, so more candidates may still be worth searching for. It is
 * {@see RunAutomaticSearchAction}'s `availableSlots()` check, not this row's
 * own status, that stops the search once every slot is taken, and lets it
 * resume automatically once one frees up (see
 * {@see AutomaticStrategySearchCommand}).
 *
 * `symbol` is nullable: it is no longer chosen by the user at "Iniciar
 * automático" time, but recorded once a search attempt's
 * {@see OpportunityScanner}-selected candidates yield a
 * selectable strategy (see {@see RunAutomaticSearchAction}).
 *
 * `last_cycle` is a snapshot of the most recent search attempt only (which
 * assets were evaluated, their outcome, and a small summary) — it is
 * overwritten on every cycle, never accumulated into a history.
 */
#[Fillable(['account_id', 'symbol', 'timeframe', 'capital', 'mode', 'status', 'last_searched_at', 'next_search_at', 'last_cycle'])]
class AutomaticSearchState extends Model
{
    /** @use HasFactory<AutomaticSearchStateFactory> */
    use HasFactory;

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
            'last_searched_at' => 'datetime',
            'next_search_at' => 'datetime',
            'last_cycle' => 'array',
        ];
    }

    /**
     * Get the trading account this automatic search state belongs to.
     *
     * @return BelongsTo<TradingAccount, $this>
     */
    public function tradingAccount(): BelongsTo
    {
        return $this->belongsTo(TradingAccount::class, 'account_id');
    }

    /**
     * Whether a new search attempt is due: either none has ever run, or the
     * configured retry interval has elapsed since the last one.
     */
    public function isDue(): bool
    {
        return $this->next_search_at === null || CarbonImmutable::now()->gte($this->next_search_at);
    }
}
