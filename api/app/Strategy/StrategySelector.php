<?php

namespace App\Strategy;

/**
 * Selects, at most, one {@see StrategyCandidate} out of the candidates that
 * already passed VALIDATION, using Pareto dominance instead of an arbitrary
 * weighted score.
 *
 * It compares each {@see ValidationResult}'s `validationEvaluation` — the
 * out-of-sample metrics, not the TRAIN metrics `StrategyCandidate->evaluation`
 * carries. TRAIN is what {@see StrategyDiscovery} used to decide which
 * candidates were worth evaluating on VALIDATION in the first place; picking
 * the final winner on TRAIN again would reward whichever candidate best fit
 * data it has already "seen", not the one that actually generalized.
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
 * Profit Factor is listed as a desirable criterion for this block. It is
 * now exposed by {@see StrategyEvaluation}, but is intentionally left out
 * of the dominance comparison in this version. Commissions, slippage,
 * per-period consistency, out-of-sample testing across different market
 * regimes, statistical confidence, and a more sophisticated selection
 * mechanism are all left for future iterations.
 */
final class StrategySelector
{
    /**
     * @param  array<string, ValidationResult>  $survivors  keyed by strategy name; every entry must have already passed VALIDATION
     */
    public function select(array $survivors): ?StrategyCandidate
    {
        $nonDominated = $this->rejectDominated($survivors);

        return count($nonDominated) === 1 ? array_values($nonDominated)[0]->candidate : null;
    }

    /**
     * @param  array<string, ValidationResult>  $survivors
     * @return array<string, ValidationResult>
     */
    private function rejectDominated(array $survivors): array
    {
        return array_filter(
            $survivors,
            fn (string $key): bool => ! $this->isDominatedByAnother($key, $survivors),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @param  array<string, ValidationResult>  $survivors
     */
    private function isDominatedByAnother(string $key, array $survivors): bool
    {
        foreach ($survivors as $otherKey => $other) {
            if ($otherKey === $key) {
                continue;
            }

            if ($this->dominates($other, $survivors[$key])) {
                return true;
            }
        }

        return false;
    }

    private function dominates(ValidationResult $a, ValidationResult $b): bool
    {
        $evaluationA = $a->validationEvaluation;
        $evaluationB = $b->validationEvaluation;

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
