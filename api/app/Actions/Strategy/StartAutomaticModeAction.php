<?php

namespace App\Actions\Strategy;

use App\Console\Commands\ProcessAutomaticTradingCommand;
use App\Models\ActiveStrategy;
use App\Models\BotEvent;
use App\Models\TradingAccount;
use InvalidArgumentException;

/**
 * Application-level use case for "Iniciar automático": moves an account's
 * current active strategy from `applied`/`stopped` into `running`, which is
 * the only status {@see ProcessAutomaticTradingCommand}
 * picks up.
 */
final readonly class StartAutomaticModeAction
{
    public function __invoke(TradingAccount $account): ActiveStrategy
    {
        $active = $account->activeStrategies()->latest('id')->first();

        if ($active === null) {
            throw new InvalidArgumentException('No hay ninguna estrategia aplicada. Aplicá una estrategia primero.');
        }

        if ($active->status !== ActiveStrategy::STATUS_RUNNING) {
            $active->update(['status' => ActiveStrategy::STATUS_RUNNING, 'stopped_at' => null]);

            BotEvent::query()->create([
                'account_id' => $account->id,
                'event_type' => 'bot_started',
                'asset' => $active->symbol,
                'message' => "Modo automático iniciado para {$active->symbol} ({$active->timeframe}).",
            ]);
        }

        return $active;
    }
}
