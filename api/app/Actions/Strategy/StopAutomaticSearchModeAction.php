<?php

namespace App\Actions\Strategy;

use App\Models\ActiveStrategy;
use App\Models\AutomaticSearchState;
use App\Models\BotEvent;
use App\Models\TradingAccount;
use InvalidArgumentException;

/**
 * Application-level use case for "Detener" in Modo Automático: stops the
 * autonomous search-retry loop and, if a strategy was applied and started by
 * it, also stops the trading execution loop via {@see StopAutomaticModeAction}
 * — a single "Detener" turns off everything "Iniciar automático" turned on.
 */
final readonly class StopAutomaticSearchModeAction
{
    public function __construct(
        private StopAutomaticModeAction $stopExecutionAction,
    ) {}

    public function __invoke(TradingAccount $account): AutomaticSearchState
    {
        $state = $account->automaticSearchState()->first();
        $hasRunningStrategy = $account->activeStrategies()
            ->where('status', ActiveStrategy::STATUS_RUNNING)
            ->exists();

        $isSearchRunning = $state !== null && $state->status === AutomaticSearchState::STATUS_RUNNING;

        if (! $isSearchRunning && ! $hasRunningStrategy) {
            throw new InvalidArgumentException('El modo automático no está en ejecución.');
        }

        if ($isSearchRunning) {
            $state->update(['status' => AutomaticSearchState::STATUS_STOPPED]);

            BotEvent::query()->create([
                'account_id' => $account->id,
                'event_type' => 'automatic_mode_stopped',
                'asset' => $state->symbol,
                'message' => 'Modo automático detenido.',
            ]);
        }

        if ($hasRunningStrategy) {
            ($this->stopExecutionAction)($account);
        }

        return $state->fresh();
    }
}
