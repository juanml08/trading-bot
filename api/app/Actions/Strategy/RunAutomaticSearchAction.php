<?php

namespace App\Actions\Strategy;

use App\MarketData\Timeframe;
use App\Models\ActiveStrategy;
use App\Models\AutomaticSearchState;
use App\Models\BotEvent;
use App\Models\TradingAccount;
use App\Opportunity\OpportunityCandidate;
use App\Opportunity\OpportunityScanner;
use App\Strategy\Strategy;
use App\Strategy\StrategyCatalog;
use App\Strategy\StrategyPipelineResult;
use App\Strategy\ValidationResult;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Application-level use case for one "Modo Automático" search attempt:
 * Opportunity Scanner → Buscar → Backtesting → Validation → Selector →
 * (Aplicar automáticamente sobre la primera oportunidad seleccionable | esperar
 * al próximo intento si ninguna lo es).
 *
 * Does nothing if the account already has a {@see ActiveStrategy} with
 * `status = running` — once a strategy is active, searching again is not
 * necessary (and would be wasted work) until that strategy stops.
 *
 * The Scanner only narrows down which symbols are worth evaluating — it
 * never decides BUY/SELL/HOLD or declares anything profitable. This still
 * delegates every actual selection decision to {@see SearchStrategiesAction}
 * exactly like "Buscar estrategia" does, once per candidate symbol, so
 * Manual and Automático are guaranteed to use the same selection rules.
 *
 * Logs an `opportunities_scanned` {@see BotEvent} right after scanning and
 * before evaluating any of them, so the Activity console shows which
 * candidates the Scanner selected (in its ranked order) before showing what
 * the Strategy Pipeline did with each one.
 *
 * Also records a per-asset review (symbol, evaluation time, outcome, and a
 * short reason when one is already available from {@see ValidationResult})
 * for every candidate actually evaluated this cycle, plus a small summary
 * (assets reviewed, candidates found). This is a transparency layer only —
 * it reads outcomes {@see SearchStrategiesAction} already produced, it does
 * not change how a candidate is discovered, validated, or selected. The
 * review is persisted on {@see AutomaticSearchState::$last_cycle} as a
 * snapshot of the most recent cycle only (overwritten every time, not a
 * history), and a `automatic_search_completed` {@see BotEvent} summarizes it
 * in the Activity console.
 */
final readonly class RunAutomaticSearchAction
{
    /**
     * Maps {@see StrategyPipeline}'s `failedCriteria` names to the short,
     * user-facing reason shown for a discarded asset. Deliberately reuses
     * those existing criterion names instead of inventing new discard
     * categories.
     */
    private const array DISCARD_REASONS = [
        'minimumTrades' => 'pocas operaciones',
        'minimumWinRate' => 'win rate bajo',
        'maximumDrawdown' => 'riesgo alto',
        'minimumProfitLoss' => 'rentabilidad insuficiente',
    ];

    /**
     * @param  array<string, Strategy>|null  $strategies  keyed by strategy name; defaults to
     *                                                    {@see StrategyCatalog::all()} in production. Overridable only so tests can
     *                                                    exercise this action with deterministic strategy doubles, exactly like
     *                                                    "Buscar estrategia" already does for {@see SearchStrategiesAction}.
     */
    public function __construct(
        private SearchStrategiesAction $searchAction,
        private ActivateStrategyAction $activateAction,
        private StartAutomaticModeAction $startAction,
        private OpportunityScanner $scanner,
        private ?array $strategies = null,
    ) {}

    public function __invoke(AutomaticSearchState $state): void
    {
        $account = $state->tradingAccount;

        $hasRunningStrategy = $account->activeStrategies()
            ->where('status', ActiveStrategy::STATUS_RUNNING)
            ->exists();

        if ($hasRunningStrategy) {
            return;
        }

        $now = CarbonImmutable::now();

        try {
            $this->attemptSearch($state, $account, $now);
        } catch (Throwable $exception) {
            // A failure here (e.g. Binance unreachable) must still push
            // `next_search_at` forward — otherwise it stays due and
            // AutomaticStrategySearchCommand's everyMinute() schedule retries
            // it every minute instead of waiting the configured interval.
            // The caller (that command) remains responsible for logging the
            // `error` BotEvent, so the exception keeps propagating.
            $this->rescheduleAfterFailure($state, $now);

            throw $exception;
        }
    }

    private function attemptSearch(AutomaticSearchState $state, TradingAccount $account, CarbonImmutable $now): void
    {
        BotEvent::query()->create([
            'account_id' => $account->id,
            'event_type' => 'automatic_search_started',
            'asset' => null,
            'message' => 'Búsqueda automática iniciada.',
        ]);

        // Logged before any strategy is evaluated, and in the Scanner's own
        // ranked order, so the Activity reflects Scanner → oportunidades →
        // evaluación exactly as it happens — never after the fact. This is
        // purely informational: the Scanner does not decide BUY/SELL/HOLD or
        // declare any of these candidates profitable, it only narrows down
        // which symbols are worth handing to the Strategy Pipeline.
        $candidates = $this->scanner->scan();

        if ($candidates === []) {
            BotEvent::query()->create([
                'account_id' => $account->id,
                'event_type' => 'opportunities_scanned',
                'asset' => null,
                'message' => 'No se encontraron oportunidades para evaluar.',
            ]);
            $this->scheduleNextAttempt($state, $now, assetReviews: []);

            return;
        }

        BotEvent::query()->create([
            'account_id' => $account->id,
            'event_type' => 'opportunities_scanned',
            'asset' => null,
            'message' => 'Oportunidades seleccionadas para evaluar: '.$this->symbolList($candidates).'.',
        ]);

        $timeframe = Timeframe::from($state->timeframe);
        $lookbackDays = (int) config('trading.automatic_search.lookback_days');
        $strategies = $this->strategies ?? StrategyCatalog::all();

        $assetReviews = [];

        foreach ($candidates as $candidate) {
            $result = ($this->searchAction)(
                $candidate->symbol,
                $timeframe,
                $now->subDays($lookbackDays),
                $now,
                $strategies,
                (string) $state->capital,
            );

            BotEvent::query()->create([
                'account_id' => $account->id,
                'event_type' => 'strategies_evaluated',
                'asset' => $candidate->symbol,
                'message' => 'Se evaluaron '.count($strategies)." estrategia(s) sobre {$candidate->symbol}.",
            ]);

            $assetReviews[] = $this->reviewFor($candidate->symbol, $now, $result);

            if ($result->selectedCandidate === null) {
                continue;
            }

            BotEvent::query()->create([
                'account_id' => $account->id,
                'event_type' => 'strategy_selected_automatically',
                'asset' => $candidate->symbol,
                'message' => "Estrategia seleccionada automáticamente: {$result->selectedCandidate->strategyName} ({$candidate->symbol}).",
            ]);

            ($this->activateAction)(
                $account,
                $result->selectedCandidate->strategyName,
                $candidate->symbol,
                $timeframe,
                (string) $state->capital,
                $state->mode,
            );

            BotEvent::query()->create([
                'account_id' => $account->id,
                'event_type' => 'strategy_applied_automatically',
                'asset' => $candidate->symbol,
                'message' => "Estrategia {$result->selectedCandidate->strategyName} aplicada automáticamente sobre {$candidate->symbol}.",
            ]);

            ($this->startAction)($account);

            $cycle = $this->cycleSummary($now, $assetReviews);

            $state->update([
                'symbol' => $candidate->symbol,
                'last_searched_at' => $now,
                'next_search_at' => null,
                'last_cycle' => $cycle,
            ]);

            $this->logCycleCompleted($account, $cycle);

            return;
        }

        $this->scheduleNextAttempt($state, $now, $assetReviews);
    }

    /**
     * Classifies one already-evaluated candidate for the Activity's "Mercado
     * analizado" view, reusing exactly what {@see SearchStrategiesAction}
     * returned — it does not re-run or re-judge Discovery/Validation/Selection.
     *
     * @return array{symbol: string, evaluatedAt: string, status: string, reason: string|null}
     */
    private function reviewFor(string $symbol, CarbonImmutable $evaluatedAt, StrategyPipelineResult $result): array
    {
        if ($result->validationResults === []) {
            $status = 'no_opportunity';
            $reason = null;
        } elseif ($result->selectedCandidate !== null) {
            $status = 'candidate_found';
            $reason = null;
        } else {
            $status = 'discarded';
            $reason = $this->discardReason($result->validationResults);
        }

        return [
            'symbol' => $symbol,
            'evaluatedAt' => $evaluatedAt->toISOString(),
            'status' => $status,
            'reason' => $reason,
        ];
    }

    /**
     * @param  ValidationResult[]  $validationResults
     */
    private function discardReason(array $validationResults): ?string
    {
        foreach ($validationResults as $validationResult) {
            if ($validationResult->failedCriteria !== []) {
                return self::DISCARD_REASONS[$validationResult->failedCriteria[0]] ?? null;
            }
        }

        // Every candidate passed VALIDATION but none dominated the others
        // (see StrategySelector) — there simply wasn't a clear winner.
        return 'sin ganador claro entre las candidatas';
    }

    /**
     * @param  array<int, array{symbol: string, evaluatedAt: string, status: string, reason: string|null}>  $assetReviews
     * @return array{completedAt: string, assetsReviewed: int, candidatesFound: int, assets: array<int, array{symbol: string, evaluatedAt: string, status: string, reason: string|null}>}
     */
    private function cycleSummary(CarbonImmutable $now, array $assetReviews): array
    {
        return [
            'completedAt' => $now->toISOString(),
            'assetsReviewed' => count($assetReviews),
            'candidatesFound' => count(array_filter(
                $assetReviews,
                fn (array $review): bool => $review['status'] === 'candidate_found',
            )),
            'assets' => $assetReviews,
        ];
    }

    /**
     * @param  array{completedAt: string, assetsReviewed: int, candidatesFound: int, assets: array<int, array{symbol: string, evaluatedAt: string, status: string, reason: string|null}>}  $cycle
     */
    private function logCycleCompleted(TradingAccount $account, array $cycle): void
    {
        BotEvent::query()->create([
            'account_id' => $account->id,
            'event_type' => 'automatic_search_completed',
            'asset' => null,
            'message' => "Búsqueda completada: {$cycle['assetsReviewed']} activo(s) revisado(s), {$cycle['candidatesFound']} candidato(s) encontrado(s).",
        ]);
    }

    /**
     * @param  OpportunityCandidate[]  $candidates
     */
    private function symbolList(array $candidates): string
    {
        return implode(', ', array_map(fn (OpportunityCandidate $candidate): string => $candidate->symbol, $candidates));
    }

    /**
     * @param  array<int, array{symbol: string, evaluatedAt: string, status: string, reason: string|null}>  $assetReviews
     */
    private function scheduleNextAttempt(AutomaticSearchState $state, CarbonImmutable $now, array $assetReviews): void
    {
        $nextSearchAt = $this->nextRetryAt($now);
        $cycle = $this->cycleSummary($now, $assetReviews);

        $state->update([
            'last_searched_at' => $now,
            'next_search_at' => $nextSearchAt,
            'last_cycle' => $cycle,
        ]);

        BotEvent::query()->create([
            'account_id' => $state->account_id,
            'event_type' => 'no_candidate_found',
            'asset' => null,
            'message' => 'No se encontró una estrategia seleccionable.',
        ]);

        BotEvent::query()->create([
            'account_id' => $state->account_id,
            'event_type' => 'next_search_scheduled',
            'asset' => null,
            // No se hornea aquí una hora formateada: `next_search_at` ya se
            // expone crudo (ISO, UTC) vía AutomaticModeStatusController y el
            // frontend lo convierte a hora local con formatoHora(). Un
            // 'H:i' calculado en el timezone del servidor (UTC) mostraría
            // una hora distinta a la que ya ve el usuario en el widget de
            // estado, dando la falsa impresión de que la búsqueda no corrió
            // cuando debía.
            'message' => 'Próxima búsqueda programada.',
        ]);

        $this->logCycleCompleted($state->tradingAccount, $cycle);
    }

    /**
     * The single place that computes how far out a retry lands, so a
     * mid-search failure (see `rescheduleAfterFailure()`) and the normal
     * "no candidate" path (see `scheduleNextAttempt()`) can never disagree
     * about the configured interval.
     */
    private function nextRetryAt(CarbonImmutable $now): CarbonImmutable
    {
        return $now->addSeconds((int) config('trading.automatic_search.retry_seconds'));
    }

    /**
     * Pushes `next_search_at` out by the configured retry interval after an
     * attempt failed partway through, without touching `last_searched_at` or
     * `last_cycle` — no cycle actually completed, so there is nothing to
     * record for either. This is what keeps a transient failure (e.g. an
     * unreachable Binance) from leaving the state permanently due and being
     * retried on every scheduler tick instead of waiting the interval.
     */
    private function rescheduleAfterFailure(AutomaticSearchState $state, CarbonImmutable $now): void
    {
        $state->update([
            'next_search_at' => $this->nextRetryAt($now),
        ]);
    }
}
