<?php

namespace App\Models;

use Database\Factories\BotEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'event_type', 'asset', 'message', 'data'])]
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
}
