<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'account_id',
    'max_risk_per_trade',
    'max_daily_loss',
    'max_open_trades',
    'stop_loss_percent',
    'take_profit_percent',
    'max_capital_per_trade',
])]
class RiskSetting extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_risk_per_trade' => 'decimal:4',
            'max_daily_loss' => 'decimal:4',
            'stop_loss_percent' => 'decimal:4',
            'take_profit_percent' => 'decimal:4',
            'max_capital_per_trade' => 'decimal:8',
        ];
    }

    /**
     * Get the trading account that owns the risk setting.
     *
     * @return BelongsTo<TradingAccount, $this>
     */
    public function tradingAccount(): BelongsTo
    {
        return $this->belongsTo(TradingAccount::class, 'account_id');
    }
}
