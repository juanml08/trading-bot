<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TradingAccount extends Model
{
    /**
     * Get the user that owns the trading account.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the risk setting associated with the trading account.
     *
     * @return HasOne<RiskSetting, $this>
     */
    public function riskSetting(): HasOne
    {
        return $this->hasOne(RiskSetting::class, 'account_id');
    }

    /**
     * Get the trades belonging to the trading account.
     *
     * @return HasMany<Trade, $this>
     */
    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class, 'account_id');
    }

    /**
     * Get the bot events for the trading account.
     *
     * @return HasMany<BotEvent, $this>
     */
    public function botEvents(): HasMany
    {
        return $this->hasMany(BotEvent::class, 'account_id');
    }
}
