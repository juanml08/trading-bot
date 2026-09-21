<?php

namespace App\Actions\Strategy;

use App\MarketData\Timeframe;
use App\Models\AutomaticSearchState;
use App\Models\BotEvent;
use App\Models\TradingAccount;

/**
 * Application-level use case for "Iniciar automático" (Modo Automático):
 * starts the autonomous strategy-selection loop for an account and
 * immediately attempts a first search, so the user does not have to Buscar
 * and Aplicar by hand — see {@see RunAutomaticSearchAction}.
 *
 * Idempotent: calling it again while already running does not re-announce
 * "Modo automático iniciado", it just runs another search attempt (a no-op
 * if the account has no free active-cycle slot — see RunAutomaticSearchAction).
 */
final readonly class StartAutomaticSearchModeAction
{
    public function __construct(
        private RunAutomaticSearchAction $runAction,
    ) {}

    public function __invoke(
        TradingAccount $account,
        Timeframe $timeframe,
        string $capital,
        string $mode,
    ): AutomaticSearchState {
        $state = $account->automaticSearchState()->first() ?? new AutomaticSearchState(['account_id' => $account->id]);
        $wasRunning = $state->exists && $state->status === AutomaticSearchState::STATUS_RUNNING;

        $state->fill([
            'account_id' => $account->id,
            'timeframe' => $timeframe->value,
            'capital' => $capital,
            'mode' => $mode,
            'status' => AutomaticSearchState::STATUS_RUNNING,
        ]);
        $state->save();

        if (! $wasRunning) {
            BotEvent::query()->create([
                'account_id' => $account->id,
                'event_type' => 'automatic_mode_started',
                'asset' => null,
                'message' => 'Modo automático iniciado.',
            ]);
        }

        ($this->runAction)($state->fresh());

        return $state->fresh();
    }
}
