<?php

namespace App\Models;

use Database\Factories\BotEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'active_trading_cycle_id', 'event_type', 'asset', 'message', 'data'])]
class BotEvent extends Model
{
    /** @use HasFactory<BotEventFactory> */
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
            'data' => 'array',
        ];
    }

    /**
     * Get the trading account that owns the bot event.
     *
     * @return BelongsTo<TradingAccount, $this>
     */
    public function tradingAccount(): BelongsTo
    {
        return $this->belongsTo(TradingAccount::class, 'account_id');
    }

    /**
     * Get the active trading cycle this event happened during, if it was
     * attributable to one (see the `active_trading_cycle_id` migration).
     *
     * @return BelongsTo<ActiveTradingCycle, $this>
     */
    public function activeTradingCycle(): BelongsTo
    {
        return $this->belongsTo(ActiveTradingCycle::class, 'active_trading_cycle_id');
    }
}
