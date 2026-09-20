<?php

namespace App\Actions\Strategy;

use App\Models\ActiveStrategy;
use App\Models\BotEvent;
use App\Models\TradingAccount;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Application-level use case for "Detener": stops an account's currently
 * running automatic mode. It does not touch open positions — those remain
 * open until a future SELL signal closes them, running or not.
 */
final readonly class StopAutomaticModeAction
{
    public function __invoke(TradingAccount $account): ActiveStrategy
    {
        $active = $account->activeStrategies()->latest('id')->first();

        if ($active === null || $active->status !== ActiveStrategy::STATUS_RUNNING) {
            throw new InvalidArgumentException('El modo automático no está en ejecución.');
        }

        $active->update(['status' => ActiveStrategy::STATUS_STOPPED, 'stopped_at' => CarbonImmutable::now()]);

        BotEvent::query()->create([
            'account_id' => $account->id,
            'event_type' => 'bot_stopped',
            'asset' => $active->symbol,
            'message' => "Modo automático detenido para {$active->symbol} ({$active->timeframe}).",
        ]);

        return $active;
    }
}
