<?php

namespace App\Strategy;

use App\MarketData\Candle;

/**
 * Coordinates the first end-to-end pipeline: TRAIN → StrategyEvaluator →
 * StrategyDiscovery → VALIDATION → ValidationResult → StrategySelector.
 *
 * This class only wires already-existing pieces together, in the order
 * described above; it does not implement backtesting math, Discovery's or
 * Selector's own logic, market data access, or persistence. It never knows
 * where `$candles` came from (Binance, a fixture, a file, ...) — that is a
 * concern for whatever calls this pipeline.
 *
 * Reuse of StrategyDiscovery: TRAIN candidates come from calling its public
 * `discover()` method as-is, unmodified — a fully honest reuse. VALIDATION
 * is different: the business rule requires reporting *which* of the four
 * criteria failed (see {@see ValidationResult::$failedCriteria}), but
 * `discover()` only returns an all-or-nothing pass/fail with no per-criterion
 * breakdown, and `StrategyDiscovery::meetsMinimumCriteria()` is private.
 * Modifying StrategyDiscovery to expose that breakdown is out of scope for
 * this block. The alternative of deriving the breakdown indirectly — by
 * calling `discover()` repeatedly with three of the four thresholds
 * "neutralized" to an always-passing value — was considered and rejected:
 * it would require assuming undocumented bounds of StrategyEvaluator's
 * output (e.g. that `maxDrawdownPercentage` never exceeds 100, or picking a
 * "neutral" `minimumProfitLoss` relative to a runtime `$initialCapital`),
 * which is a more fragile, implicit coupling than the alternative chosen
 * here: `failedCriteria()` below deliberately duplicates StrategyDiscovery's
 * four comparisons — a small, readable, four-line mirror of an already
 * simple check — fed by the exact same threshold values this class used to
 * build its internal `$discovery` instance, so there is a single source for
 * the criteria and no risk of TRAIN and VALIDATION disagreeing on them.
 * `failedCriteria()` is reused a second time, against TRAIN evaluations, to
 * build {@see DiscoveryResult} for every strategy — including the ones
 * `discover()` did not let through — for the same reason: it is the only
 * place that already knows, per criterion, why a strategy did not qualify.
 */
final readonly class StrategyPipeline
{
    private StrategyDiscovery $discovery;

    public function __construct(
        private StrategyEvaluator $evaluator,
        private StrategySelector $selector,
        private TrainValidationSplit $split,
        private int $minimumTrades,
        private string $minimumWinRate,
        private string $maximumDrawdown,
        private string $minimumProfitLoss,
    ) {
        $this->discovery = new StrategyDiscovery(
            minimumTrades: $minimumTrades,
            minimumWinRate: $minimumWinRate,
            maximumDrawdown: $maximumDrawdown,
            minimumProfitLoss: $minimumProfitLoss,
        );
    }

    /**
     * @param  array<string, Strategy>  $strategies  keyed by strategy name
     * @param  Candle[]  $candles  chronologically ordered (oldest first), covering both TRAIN and VALIDATION
     */
    public function run(array $strategies, array $candles, string $initialCapital): StrategyPipelineResult
    {
        $windows = $this->split->split($candles);

        $trainEvaluations = [];
        foreach ($strategies as $name => $strategy) {
            $trainEvaluations[$name] = $this->evaluator->evaluate($strategy, $windows['train'], $initialCapital);
        }

        $candidates = $this->discovery->discover($trainEvaluations);

        $discoveryResults = [];
        foreach ($trainEvaluations as $name => $trainEvaluation) {
            $failedCriteria = $this->failedCriteria($trainEvaluation);

            $discoveryResults[$name] = new DiscoveryResult(
                strategyName: $name,
                trainEvaluation: $trainEvaluation,
                passed: $failedCriteria === [],
                failedCriteria: $failedCriteria,
            );
        }

        $validationResults = [];
        $survivors = [];

        foreach ($candidates as $name => $candidate) {
            $validationEvaluation = $this->evaluator->evaluate($strategies[$name], $windows['validation'], $initialCapital);
            $failedCriteria = $this->failedCriteria($validationEvaluation);
            $passed = $failedCriteria === [];

            $validationResult = new ValidationResult(
                candidate: $candidate,
                validationEvaluation: $validationEvaluation,
                passed: $passed,
                failedCriteria: $failedCriteria,
            );

            $validationResults[] = $validationResult;

            if ($passed) {
                $survivors[$name] = $validationResult;
            }
        }

        return new StrategyPipelineResult(
            selectedCandidate: $this->selector->select($survivors),
            validationResults: $validationResults,
            discoveryResults: $discoveryResults,
        );
    }

    /**
     * @return string[]
     */
    private function failedCriteria(StrategyEvaluation $evaluation): array
    {
        $failed = [];

        if ($evaluation->totalTrades < $this->minimumTrades) {
            $failed[] = 'minimumTrades';
        }

        if (bccomp($evaluation->winRate, $this->minimumWinRate, 18) < 0) {
            $failed[] = 'minimumWinRate';
        }

        if (bccomp($evaluation->maxDrawdownPercentage, $this->maximumDrawdown, 18) > 0) {
            $failed[] = 'maximumDrawdown';
        }

        if (bccomp($evaluation->profitLoss, $this->minimumProfitLoss, 18) < 0) {
            $failed[] = 'minimumProfitLoss';
        }

        return $failed;
    }
}
