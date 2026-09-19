<?php

namespace App\Strategy;

/**
 * Selects, at most, one {@see StrategyCandidate} out of the candidates that
 * already passed {@see StrategyDiscovery}, using Pareto dominance instead of
 * an arbitrary weighted score.
 *
 * A candidate A dominates a candidate B when A is at least as good as B on
 * every criterion (profit/loss percentage, max drawdown, win rate, total
 * trades) and strictly better on at least one. Any candidate dominated by
 * another is discarded. Among what remains:
 *
 * - No candidates left: returns null.
 * - Exactly one left: it is selected.
 * - More than one left (none dominates the others): returns null. This is
 *   deliberate — the available metrics do not show a clear enough
 *   difference to prefer one candidate over another, and picking one
 *   arbitrarily (e.g. by profit alone) would misrepresent that as an
 *   informed decision.
 *
 * This is a deliberately conservative first version. It does not evaluate
 * strategies, does not touch historical data, brokers, or persistence, and
 * only reads the metrics already computed by {@see StrategyEvaluator}.
 *
 * Profit Factor is listed as a desirable criterion for this block but is
 * not yet exposed by {@see StrategyEvaluation}, so it is intentionally left
 * out of this version. Commissions, slippage, per-period consistency,
 * train/validation splits, out-of-sample testing, different market
 * regimes, statistical confidence, and a more sophisticated selection
 * mechanism are all left for future iterations.
 */
final class StrategySelector
{
    /**
     * @param  array<string, StrategyCandidate>  $candidates  keyed by strategy name
     */
    public function select(array $candidates): ?StrategyCandidate
    {
        $nonDominated = $this->rejectDominated($candidates);

        return count($nonDominated) === 1 ? array_values($nonDominated)[0] : null;
    }

    /**
     * @param  array<string, StrategyCandidate>  $candidates
     * @return array<string, StrategyCandidate>
     */
    private function rejectDominated(array $candidates): array
    {
        return array_filter(
            $candidates,
            fn (string $key): bool => ! $this->isDominatedByAnother($key, $candidates),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @param  array<string, StrategyCandidate>  $candidates
     */
    private function isDominatedByAnother(string $key, array $candidates): bool
    {
        foreach ($candidates as $otherKey => $other) {
            if ($otherKey === $key) {
                continue;
            }

            if ($this->dominates($other, $candidates[$key])) {
                return true;
            }
        }

        return false;
    }

    private function dominates(StrategyCandidate $a, StrategyCandidate $b): bool
    {
        $evaluationA = $a->evaluation;
        $evaluationB = $b->evaluation;

        $profitComparison = bccomp($evaluationA->profitLossPercentage, $evaluationB->profitLossPercentage, 18);
        $drawdownComparison = bccomp($evaluationA->maxDrawdownPercentage, $evaluationB->maxDrawdownPercentage, 18);
        $winRateComparison = bccomp($evaluationA->winRate, $evaluationB->winRate, 18);
        $tradesComparison = $evaluationA->totalTrades <=> $evaluationB->totalTrades;

        $atLeastAsGoodOnEveryCriterion = $profitComparison >= 0
            && $drawdownComparison <= 0
            && $winRateComparison >= 0
            && $tradesComparison >= 0;

        if (! $atLeastAsGoodOnEveryCriterion) {
            return false;
        }

        return $profitComparison > 0
            || $drawdownComparison < 0
            || $winRateComparison > 0
            || $tradesComparison > 0;
    }
}
