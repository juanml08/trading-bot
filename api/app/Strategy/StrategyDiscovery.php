<?php

namespace App\Strategy;

/**
 * Filters strategy evaluations down to the ones that meet a set of minimum
 * viability criteria, configured at construction time.
 *
 * This is an objective pass/fail filter only: it does not rank, score, or
 * pick a "best" strategy among the candidates it returns. It performs no
 * calculations of its own — it only reads the metrics already produced by
 * {@see StrategyEvaluator}.
 */
final readonly class StrategyDiscovery
{
    public function __construct(
        private int $minimumTrades,
        private string $minimumWinRate,
        private string $maximumDrawdown,
        private string $minimumProfitLoss,
    ) {}

    /**
     * @param  array<string, StrategyEvaluation>  $evaluations  keyed by strategy name
     * @return array<string, StrategyCandidate> keyed by strategy name, only entries that meet all criteria
     */
    public function discover(array $evaluations): array
    {
        $candidates = [];

        foreach ($evaluations as $strategyName => $evaluation) {
            if ($this->meetsMinimumCriteria($evaluation)) {
                $candidates[$strategyName] = new StrategyCandidate($strategyName, $evaluation);
            }
        }

        return $candidates;
    }

    private function meetsMinimumCriteria(StrategyEvaluation $evaluation): bool
    {
        return $evaluation->totalTrades >= $this->minimumTrades
            && bccomp($evaluation->winRate, $this->minimumWinRate, 18) >= 0
            && bccomp($evaluation->maxDrawdownPercentage, $this->maximumDrawdown, 18) <= 0
            && bccomp($evaluation->profitLoss, $this->minimumProfitLoss, 18) >= 0;
    }
}
