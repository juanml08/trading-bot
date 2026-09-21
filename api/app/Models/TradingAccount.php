<?php

namespace App\Models;

use Database\Factories\TradingAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['user_id', 'exchange', 'account_type', 'api_key_encrypted', 'api_secret_encrypted', 'is_active'])]
class TradingAccount extends Model
{
    /** @use HasFactory<TradingAccountFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * The single local trading account this project operates with today —
     * there is no authentication/login flow yet to own one account per
     * user, so every automatic-mode endpoint operates against this one.
     * `account_type` stays `paper`: Binance Real is not implemented.
     */
    public static function current(): self
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'local@trading-bot.test'],
            ['name' => 'Local', 'password_hash' => str()->random(32)],
        );

        return static::query()->firstOrCreate(
            ['user_id' => $user->id, 'exchange' => 'binance'],
            ['account_type' => 'paper'],
        );
    }

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

    /**
     * Get the active strategy assignments for the trading account.
     *
     * @return HasMany<ActiveStrategy, $this>
     */
    public function activeStrategies(): HasMany
    {
        return $this->hasMany(ActiveStrategy::class, 'account_id');
    }

    /**
     * Get the automatic mode (autonomous strategy selection) state for the
     * trading account, if it was ever started.
     *
     * @return HasOne<AutomaticSearchState, $this>
     */
    public function automaticSearchState(): HasOne
    {
        return $this->hasOne(AutomaticSearchState::class, 'account_id');
    }

    /**
     * Get the historical "Modo Automático" search cycles for the trading
     * account (see {@see AutomaticSearchCycle}).
     *
     * @return HasMany<AutomaticSearchCycle, $this>
     */
    public function automaticSearchCycles(): HasMany
    {
        return $this->hasMany(AutomaticSearchCycle::class, 'account_id');
    }
}
