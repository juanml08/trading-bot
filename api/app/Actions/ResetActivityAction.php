<?php

namespace App\Actions;

use App\Models\BotEvent;
use App\Models\TradingAccount;

/**
 * Application-level use case for "Resetear actividad": clears the control
 * center's Activity log for one account. This only ever deletes
 * {@see BotEvent} rows — it must never touch Trade, Order,
 * ActiveStrategy, AutomaticSearchState, or any other bot data, and it never
 * starts, stops, or otherwise changes the running bot.
 */
final readonly class ResetActivityAction
{
    public function __invoke(TradingAccount $account): void
    {
        $account->botEvents()->delete();
    }
}
