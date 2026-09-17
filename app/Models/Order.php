<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['trade_id', 'exchange_order_id', 'type', 'side', 'price', 'quantity', 'status', 'executed_at'])]
class Order extends Model
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
            'price' => 'decimal:12',
            'quantity' => 'decimal:12',
            'executed_at' => 'datetime',
        ];
    }

    /**
     * Get the trade that owns the order.
     *
     * @return BelongsTo<Trade, $this>
     */
    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class, 'trade_id');
    }
}
