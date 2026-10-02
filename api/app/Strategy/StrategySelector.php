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
 * - More than one left (none dominates the others): the Pareto front is a
 *   set of trade-offs (e.g. more trades but lower profit), so dominance
 *   alone cannot choose between them. In practice this was the common case
 *   (~3 of 4 assets with validated strategies ended with no selection), so
 *   {@see breakTie()} picks one deterministically from the front only —
 *   never a dominated candidate. That pick is a tie-break, not evidence that
 *   the chosen candidate is better than the others on the front.
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

        if ($nonDominated === []) {
            return null;
        }

        return count($nonDominated) === 1
            ? array_values($nonDominated)[0]->candidate
            : $this->breakTie($nonDominated);
    }

    /**
     * Resolves a Pareto front with several members using only metrics the
     * dominance comparison already reads (no new score): highest validation
     * profit/loss percentage first — profit is the objective the bot exists
     * for, while win rate, drawdown and trade count were already gated by
     * the Discovery/Validation thresholds — then lowest drawdown, highest
     * win rate, most trades, and finally the strategy name, so the result
     * never depends on input order.
     *
     * @param  array<string, ValidationResult>  $nonDominated
     */
    private function breakTie(array $nonDominated): StrategyCandidate
    {
        $results = array_values($nonDominated);

        usort($results, function (ValidationResult $a, ValidationResult $b): int {
            $evaluationA = $a->validationEvaluation;
            $evaluationB = $b->validationEvaluation;

            return bccomp($evaluationB->profitLossPercentage, $evaluationA->profitLossPercentage, 18)
                ?: bccomp($evaluationA->maxDrawdownPercentage, $evaluationB->maxDrawdownPercentage, 18)
                ?: bccomp($evaluationB->winRate, $evaluationA->winRate, 18)
                ?: $evaluationB->totalTrades <=> $evaluationA->totalTrades
                ?: strcmp($a->candidate->strategyName, $b->candidate->strategyName);
        });

        return $results[0]->candidate;
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
