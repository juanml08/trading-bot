<?php

namespace App\Actions\Strategy;

use App\MarketData\Timeframe;
use App\Models\ActiveTradingCycle;
use App\Models\AutomaticSearchCycle;
use App\Models\AutomaticSearchCycleAsset;
use App\Models\AutomaticSearchState;
use App\Models\BotEvent;
use App\Models\TradingAccount;
use App\Opportunity\OpportunityCandidate;
use App\Opportunity\OpportunityScanner;
use App\Strategy\DiscoveryResult;
use App\Strategy\Strategy;
use App\Strategy\StrategyCatalog;
use App\Strategy\StrategyEvaluation;
use App\Strategy\StrategyPipelineResult;
use App\Strategy\ValidationResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Application-level use case for one "Modo Automático" search attempt:
 * Opportunity Scanner → Buscar → Backtesting → Validation → Selector →
 * Aplicar automáticamente sobre cada oportunidad seleccionable, hasta
 * `MAX_ACTIVE_CYCLES` {@see ActiveTradingCycle}s activos simultáneos.
 *
 * Does nothing if the account has no free slot — i.e. it already has
 * `config('trading.active_cycles.max_active')` {@see ActiveTradingCycle}s in
 * a non-terminal state (HOLD/POSITION_OPEN) — since activating another
 * candidate is not possible (and evaluating one would be wasted work) until
 * one of those cycles reaches a terminal state (CLOSED/EXPIRED).
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
 * Also records a per-asset review (symbol, evaluation time, outcome, a short
 * reason when one is already available from {@see ValidationResult}, and a
 * per-strategy diagnostic breakdown — see `strategyDiagnostics()`) for every
 * candidate actually evaluated this cycle, plus a small summary (assets
 * reviewed, candidates found). This is a transparency layer only — it reads
 * outcomes {@see SearchStrategiesAction} already produced (by way of
 * {@see StrategyPipelineResult}'s {@see DiscoveryResult} and
 * {@see ValidationResult} entries), it does not change how a candidate is
 * discovered, validated, or selected, and it does not recompute any metric
 * {@see StrategyEvaluator} did not already produce. The review is persisted
 * on {@see AutomaticSearchState::$last_cycle} as a snapshot of the most
 * recent cycle only (overwritten every time, not a history), and a
 * `automatic_search_completed` {@see BotEvent} summarizes it in the Activity
 * console. The same review is additionally persisted, unchanged, as a
 * permanent {@see AutomaticSearchCycle} history row (with its
 * {@see AutomaticSearchCycleAsset} and
 * {@see \App\Models\AutomaticSearchCycleStrategy} children) every time
 * `last_cycle` is written — so a completed cycle is never lost even though
 * `last_cycle` itself keeps being overwritten. Persisting is purely a
 * consequence of the result already computed above: it does not re-run or
 * re-judge Discovery/Validation/Selection.
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
        private ActivateTradingCycleAction $activateCycleAction,
        private StartAutomaticModeAction $startAction,
        private OpportunityScanner $scanner,
        private ?array $strategies = null,
    ) {}

    public function __invoke(AutomaticSearchState $state): void
    {
        $account = $state->tradingAccount;

        if ($this->availableSlots($account) <= 0) {
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
        $availableSlots = $this->availableSlots($account);
        $activatedSymbol = null;

        foreach ($candidates as $candidate) {
            // Once every free slot is filled, stop evaluating further
            // candidates entirely — not just activating them — there is
            // nothing left to do with the result of an evaluation that can't
            // be acted on.
            if ($availableSlots <= 0) {
                break;
            }

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

            // No two non-terminal cycles for the same (account, asset): a
            // symbol already HOLD/POSITION_OPEN from an earlier search does
            // not get a second cycle, even if it is selectable again.
            if ($this->hasActiveCycleForSymbol($account, $candidate->symbol)) {
                continue;
            }

            $cycle = ($this->activateCycleAction)(
                $account,
                $result->selectedCandidate->strategyName,
                $candidate->symbol,
                $timeframe,
                (string) $state->capital,
                $state->mode,
            );

            BotEvent::query()->create([
                'account_id' => $account->id,
                'active_trading_cycle_id' => $cycle->id,
                'event_type' => 'strategy_applied_automatically',
                'asset' => $candidate->symbol,
                'message' => "Estrategia {$result->selectedCandidate->strategyName} aplicada automáticamente sobre {$candidate->symbol}.",
            ]);

            ($this->startAction)($account);

            $availableSlots--;
            $activatedSymbol = $candidate->symbol;
        }

        if ($activatedSymbol !== null) {
            $cycle = $this->cycleSummary($now, $assetReviews);

            // History is persisted before `last_cycle` is written: if the
            // history insert fails partway (see `persistCycleHistory()`'s own
            // transaction), the exception must propagate before `last_cycle`
            // ever claims a cycle that was not durably recorded.
            $this->persistCycleHistory($account, $now, $cycle);

            // Same interval as the "no candidate" path below: activating a
            // cycle only fills one of up to `max_active` slots, so the
            // search must keep retrying on the configured cadence instead of
            // going quiet — `availableSlots()` is what actually stops it
            // once every slot is taken (see `__invoke()` above).
            $state->update([
                'symbol' => $activatedSymbol,
                'last_searched_at' => $now,
                'next_search_at' => $this->nextRetryAt($now),
                'last_cycle' => $cycle,
            ]);

            $this->logCycleCompleted($account, $cycle);

            return;
        }

        $this->scheduleNextAttempt($state, $now, $assetReviews);
    }

    /**
     * How many more {@see ActiveTradingCycle}s this account may activate
     * right now: `config('trading.active_cycles.max_active')` minus its
     * currently non-terminal (HOLD/POSITION_OPEN) cycles. Never negative.
     */
    private function availableSlots(TradingAccount $account): int
    {
        $maxActive = (int) config('trading.active_cycles.max_active');
        $activeCount = $account->activeTradingCycles()->active()->count();

        return max(0, $maxActive - $activeCount);
    }

    /**
     * Whether the account already has a non-terminal cycle for this symbol
     * — the application-level guard against two simultaneous cycles for the
     * same (account, asset), since there is no DB constraint for it yet
     * (see {@see ActiveTradingCycle}'s migration).
     */
    private function hasActiveCycleForSymbol(TradingAccount $account, string $symbol): bool
    {
        return $account->activeTradingCycles()
            ->active()
            ->whereHas('asset', fn ($query) => $query->where('symbol', strtoupper($symbol)))
            ->exists();
    }

    /**
     * Classifies one already-evaluated candidate for the Activity's "Mercado
     * analizado" view, reusing exactly what {@see SearchStrategiesAction}
     * returned — it does not re-run or re-judge Discovery/Validation/Selection.
     *
     * @return array{symbol: string, evaluatedAt: string, status: string, reason: string|null, strategies: array<int, array{name: string, status: string, discovery: array{passed: bool, failedCriteria: string[], metrics: array<string, mixed>}, validation: array{passed: bool, failedCriteria: string[], metrics: array<string, mixed>}|null}>}
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
            'strategies' => $this->strategyDiagnostics($result),
        ];
    }

    /**
     * Breaks the asset-level outcome down per strategy, for diagnostic
     * purposes only: exactly what stage each strategy reached
     * (Discovery/Validation/Selector), why it was discarded when it was,
     * and the metrics {@see StrategyEvaluator} already computed for it at
     * each stage it reached. Built entirely from
     * {@see StrategyPipelineResult::$discoveryResults} and
     * {@see StrategyPipelineResult::$validationResults} — no metric here is
     * recalculated, and none of this changes `$result->selectedCandidate`.
     *
     * @return array<int, array{name: string, status: string, discovery: array{passed: bool, failedCriteria: string[], metrics: array<string, mixed>}, validation: array{passed: bool, failedCriteria: string[], metrics: array<string, mixed>}|null}>
     */
    private function strategyDiagnostics(StrategyPipelineResult $result): array
    {
        $validationResultsByStrategyName = [];
        foreach ($result->validationResults as $validationResult) {
            $validationResultsByStrategyName[$validationResult->candidate->strategyName] = $validationResult;
        }

        $selectedStrategyName = $result->selectedCandidate?->strategyName;

        $diagnostics = [];

        foreach ($result->discoveryResults as $strategyName => $discoveryResult) {
            $validationResult = $validationResultsByStrategyName[$strategyName] ?? null;

            $diagnostics[] = [
                'name' => $strategyName,
                'status' => $this->strategyStatus($discoveryResult, $validationResult, $strategyName === $selectedStrategyName),
                'discovery' => [
                    'passed' => $discoveryResult->passed,
                    'failedCriteria' => $discoveryResult->failedCriteria,
                    'metrics' => $this->evaluationMetrics($discoveryResult->trainEvaluation),
                ],
                'validation' => $validationResult === null ? null : [
                    'passed' => $validationResult->passed,
                    'failedCriteria' => $validationResult->failedCriteria,
                    'metrics' => $this->evaluationMetrics($validationResult->validationEvaluation),
                ],
            ];
        }

        return $diagnostics;
    }

    /**
     * Distinguishes the four ways a strategy's evaluation this cycle can
     * end, from the most to the least far it got:
     *
     * - `discarded_in_discovery`: failed TRAIN Discovery, never reached VALIDATION.
     * - `discarded_in_validation`: passed Discovery but failed VALIDATION.
     * - `validated_not_selected`: passed VALIDATION but {@see StrategySelector}
     *   did not pick it (dominated by another candidate, or no candidate
     *   dominated the rest).
     * - `selected`: the one candidate {@see StrategySelector} picked.
     */
    private function strategyStatus(DiscoveryResult $discoveryResult, ?ValidationResult $validationResult, bool $isSelected): string
    {
        if (! $discoveryResult->passed) {
            return 'discarded_in_discovery';
        }

        if ($validationResult === null || ! $validationResult->passed) {
            return 'discarded_in_validation';
        }

        return $isSelected ? 'selected' : 'validated_not_selected';
    }

    /**
     * Every {@see StrategyEvaluation} field useful for explaining a discard,
     * as already computed by {@see StrategyEvaluator} — nothing here is
     * derived or recalculated.
     *
     * @return array<string, mixed>
     */
    private function evaluationMetrics(StrategyEvaluation $evaluation): array
    {
        return [
            'totalTrades' => $evaluation->totalTrades,
            'winningTrades' => $evaluation->winningTrades,
            'losingTrades' => $evaluation->losingTrades,
            'winRate' => $evaluation->winRate,
            'profitLoss' => $evaluation->profitLoss,
            'profitLossPercentage' => $evaluation->profitLossPercentage,
            'maxDrawdownPercentage' => $evaluation->maxDrawdownPercentage,
            'profitFactor' => $evaluation->profitFactor,
            'totalCosts' => $evaluation->totalCosts,
            'grossProfitLoss' => $evaluation->grossProfitLoss,
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
     * @param  array<int, array{symbol: string, evaluatedAt: string, status: string, reason: string|null, strategies: array<int, array<string, mixed>>}>  $assetReviews
     * @return array{completedAt: string, assetsReviewed: int, candidatesFound: int, assets: array<int, array{symbol: string, evaluatedAt: string, status: string, reason: string|null, strategies: array<int, array<string, mixed>>}>}
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
     * @param  array{completedAt: string, assetsReviewed: int, candidatesFound: int, assets: array<int, array{symbol: string, evaluatedAt: string, status: string, reason: string|null, strategies: array<int, array<string, mixed>>}>}  $cycle
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
     * Persists the cycle summary already built by {@see cycleSummary()} (and,
     * transitively, {@see reviewFor()}/{@see strategyDiagnostics()}) as a
     * permanent {@see AutomaticSearchCycle} history row, so it survives the
     * next cycle overwriting {@see AutomaticSearchState::$last_cycle}. Reads
     * only — every field here was already computed above; nothing is
     * recalculated.
     *
     * Wrapped in a single transaction because the cycle, its assets, and
     * their strategies must land together or not at all: a failure partway
     * through (e.g. one insert violates a constraint) must not leave a cycle
     * row with only some of its assets/strategies. The exception still
     * propagates after the rollback — callers must not treat that cycle as
     * persisted (see the two call sites, which persist history before
     * writing `last_cycle`).
     *
     * @param  array{completedAt: string, assetsReviewed: int, candidatesFound: int, assets: array<int, array{symbol: string, evaluatedAt: string, status: string, reason: string|null, strategies: array<int, array<string, mixed>>}>}  $cycle
     */
    private function persistCycleHistory(TradingAccount $account, CarbonImmutable $now, array $cycle): void
    {
        DB::transaction(function () use ($account, $now, $cycle): void {
            $cycleRecord = AutomaticSearchCycle::query()->create([
                'account_id' => $account->id,
                'started_at' => $now,
                'completed_at' => $now,
                'assets_reviewed' => $cycle['assetsReviewed'],
                'candidates_found' => $cycle['candidatesFound'],
            ]);

            foreach ($cycle['assets'] as $assetReview) {
                $assetRecord = $cycleRecord->assets()->create([
                    'symbol' => $assetReview['symbol'],
                    'status' => $assetReview['status'],
                    'evaluated_at' => $assetReview['evaluatedAt'],
                ]);

                foreach ($assetReview['strategies'] as $strategyDiagnostic) {
                    $assetRecord->strategies()->create($this->strategyHistoryAttributes($strategyDiagnostic));
                }
            }
        });
    }

    /**
     * Maps one {@see strategyDiagnostics()} entry onto
     * {@see AutomaticSearchCycleStrategy}'s columns. TRAIN metrics always
     * exist (Discovery always evaluates); VALIDATION metrics stay null for a
     * strategy that never reached Validation.
     *
     * @param  array{name: string, status: string, discovery: array{passed: bool, failedCriteria: string[], metrics: array<string, mixed>}, validation: array{passed: bool, failedCriteria: string[], metrics: array<string, mixed>}|null}  $diagnostic
     * @return array<string, mixed>
     */
    private function strategyHistoryAttributes(array $diagnostic): array
    {
        $discovery = $diagnostic['discovery'];
        $validation = $diagnostic['validation'];

        return [
            'strategy_name' => $diagnostic['name'],
            'status' => $diagnostic['status'],
            'discovery_passed' => $discovery['passed'],
            'validation_passed' => $validation['passed'] ?? null,
            'failed_criteria' => [
                'discovery' => $discovery['failedCriteria'],
                'validation' => $validation['failedCriteria'] ?? [],
            ],
            'train_total_trades' => $discovery['metrics']['totalTrades'],
            'train_winning_trades' => $discovery['metrics']['winningTrades'],
            'train_losing_trades' => $discovery['metrics']['losingTrades'],
            'train_win_rate' => $discovery['metrics']['winRate'],
            'train_profit_loss' => $discovery['metrics']['profitLoss'],
            'train_profit_loss_percentage' => $discovery['metrics']['profitLossPercentage'],
            'train_max_drawdown_percentage' => $discovery['metrics']['maxDrawdownPercentage'],
            'train_profit_factor' => $discovery['metrics']['profitFactor'],
            'train_total_costs' => $discovery['metrics']['totalCosts'],
            'train_gross_profit_loss' => $discovery['metrics']['grossProfitLoss'],
            'validation_total_trades' => $validation['metrics']['totalTrades'] ?? null,
            'validation_winning_trades' => $validation['metrics']['winningTrades'] ?? null,
            'validation_losing_trades' => $validation['metrics']['losingTrades'] ?? null,
            'validation_win_rate' => $validation['metrics']['winRate'] ?? null,
            'validation_profit_loss' => $validation['metrics']['profitLoss'] ?? null,
            'validation_profit_loss_percentage' => $validation['metrics']['profitLossPercentage'] ?? null,
            'validation_max_drawdown_percentage' => $validation['metrics']['maxDrawdownPercentage'] ?? null,
            'validation_profit_factor' => $validation['metrics']['profitFactor'] ?? null,
            'validation_total_costs' => $validation['metrics']['totalCosts'] ?? null,
            'validation_gross_profit_loss' => $validation['metrics']['grossProfitLoss'] ?? null,
        ];
    }

    /**
     * @param  array<int, array{symbol: string, evaluatedAt: string, status: string, reason: string|null, strategies: array<int, array<string, mixed>>}>  $assetReviews
     */
    private function scheduleNextAttempt(AutomaticSearchState $state, CarbonImmutable $now, array $assetReviews): void
    {
        $nextSearchAt = $this->nextRetryAt($now);
        $cycle = $this->cycleSummary($now, $assetReviews);

        // Same ordering rationale as the candidate-found path above: persist
        // history first, so a failure there leaves `last_cycle` (and the
        // BotEvents below) exactly as they were before this attempt, instead
        // of claiming a cycle that was never durably recorded.
        $this->persistCycleHistory($state->tradingAccount, $now, $cycle);

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
