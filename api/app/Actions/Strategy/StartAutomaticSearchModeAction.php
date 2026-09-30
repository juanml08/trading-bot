<?php

namespace App\Actions\Strategy;

use App\MarketData\Timeframe;
use App\Models\AutomaticSearchState;
use App\Models\BotEvent;
use App\Models\TradingAccount;
use App\Strategy\StrategyCatalog;

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
                'data' => $this->configSnapshot($timeframe, $capital, $mode),
            ]);
        }

        ($this->runAction)($state->fresh());

        return $state->fresh();
    }

    /**
     * The experiment's effective configuration at the moment it starts, so
     * it can be audited later ("¿con qué configuración exacta se ejecutó
     * este experimento?") without reconstructing it from scattered logs.
     * Reused as-is on the `automatic_mode_started` {@see BotEvent}'s `data`
     * column, which already exists and was never populated — no schema
     * change needed. Purely a read of already-defined config/arguments;
     * nothing here is derived or decided.
     *
     * @return array<string, mixed>
     */
    private function configSnapshot(Timeframe $timeframe, string $capital, string $mode): array
    {
        return [
            'timeframe' => $timeframe->value,
            'capital' => $capital,
            'mode' => $mode,
            'strategies_evaluated' => count(StrategyCatalog::discoveryCandidates()),
            'lookback_days' => (int) config('trading.automatic_search.lookback_days'),
            'max_active_cycles' => (int) config('trading.active_cycles.max_active'),
            'hold_timeout_hours' => (int) config('trading.active_cycles.hold_timeout_hours'),
            'risk_exit' => [
                'stop_loss_percent' => (string) config('trading.risk_exit.stop_loss_percent'),
                'max_holding_hours' => (int) config('trading.risk_exit.max_holding_hours'),
            ],
            'capital_config' => [
                'reserve_percent' => (string) config('trading.capital.reserve_percent'),
                'min_notional_usdt' => (string) config('trading.capital.min_notional_usdt'),
            ],
            'discovery' => config('trading.discovery'),
            'validation' => config('trading.validation'),
        ];
    }
}
