<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'account_id',
    'strategy_id',
    'asset_id',
    'entry_price',
    'exit_price',
    'quantity',
    'capital_used',
    'stop_loss',
    'take_profit',
    'profit_loss',
    'profit_loss_percent',
    'status',
    'opened_at',
    'closed_at',
])]
class Trade extends Model
{
    const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entry_price' => 'decimal:12',
            'exit_price' => 'decimal:12',
            'quantity' => 'decimal:12',
            'capital_used' => 'decimal:8',
            'stop_loss' => 'decimal:12',
            'take_profit' => 'decimal:12',
            'profit_loss' => 'decimal:8',
            'profit_loss_percent' => 'decimal:4',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * Get the trading account that owns the trade.
     *
     * @return BelongsTo<TradingAccount, $this>
     */
    public function tradingAccount(): BelongsTo
    {
        return $this->belongsTo(TradingAccount::class, 'account_id');
    }

    /**
     * Get the orders for the trade.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'trade_id');
    }

    /**
     * Get the strategy that owns the trade.
     *
     * @return BelongsTo<Strategy, $this>
     */
    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class, 'strategy_id');
    }

    /**
     * Get the asset that owns the trade.
     *
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    /**
     * Get the notifications for the trade.
     *
     * @return HasMany<Notification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'trade_id');
    }
}
