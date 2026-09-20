<?php

namespace Tests\Unit;

use App\Strategy\StrategyCandidate;
use App\Strategy\StrategyEvaluation;
use App\Strategy\StrategySelector;
use App\Strategy\ValidationResult;
use PHPUnit\Framework\TestCase;

class StrategySelectorTest extends TestCase
{
    public function test_an_empty_list_of_candidates_selects_nothing(): void
    {
        $selector = new StrategySelector;

        $this->assertNull($selector->select([]));
    }

    public function test_a_single_candidate_is_selected(): void
    {
        $selector = new StrategySelector;

        $survivor = $this->survivor('A', profitLossPercentage: '10', maxDrawdownPercentage: '5', winRate: '60', totalTrades: 20);

        $this->assertSame($survivor->candidate, $selector->select(['A' => $survivor]));
    }

    public function test_a_candidate_that_clearly_dominates_another_is_selected(): void
    {
        $selector = new StrategySelector;

        $a = $this->survivor('A', profitLossPercentage: '20', maxDrawdownPercentage: '5', winRate: '65', totalTrades: 30);
        $b = $this->survivor('B', profitLossPercentage: '10', maxDrawdownPercentage: '8', winRate: '55', totalTrades: 20);

        $this->assertSame($a->candidate, $selector->select(['A' => $a, 'B' => $b]));
    }

    public function test_a_candidate_that_is_clearly_dominated_is_not_selected_in_favor_of_the_dominant_one(): void
    {
        $selector = new StrategySelector;

        $a = $this->survivor('A', profitLossPercentage: '5', maxDrawdownPercentage: '9', winRate: '52', totalTrades: 15);
        $b = $this->survivor('B', profitLossPercentage: '15', maxDrawdownPercentage: '4', winRate: '62', totalTrades: 25);

        $this->assertSame($b->candidate, $selector->select(['A' => $a, 'B' => $b]));
    }

    public function test_two_candidates_where_neither_dominates_the_other_select_nothing(): void
    {
        $selector = new StrategySelector;

        // A has better profit but worse drawdown than B: neither dominates.
        $a = $this->survivor('A', profitLossPercentage: '20', maxDrawdownPercentage: '15', winRate: '55', totalTrades: 20);
        $b = $this->survivor('B', profitLossPercentage: '10', maxDrawdownPercentage: '5', winRate: '55', totalTrades: 20);

        $this->assertNull($selector->select(['A' => $a, 'B' => $b]));
    }

    public function test_a_candidate_that_dominates_the_other_two_is_selected(): void
    {
        $selector = new StrategySelector;

        $a = $this->survivor('A', profitLossPercentage: '25', maxDrawdownPercentage: '4', winRate: '65', totalTrades: 30);
        $b = $this->survivor('B', profitLossPercentage: '15', maxDrawdownPercentage: '8', winRate: '55', totalTrades: 20);
        $c = $this->survivor('C', profitLossPercentage: '10', maxDrawdownPercentage: '10', winRate: '50', totalTrades: 18);

        $this->assertSame($a->candidate, $selector->select(['A' => $a, 'B' => $b, 'C' => $c]));
    }

    public function test_several_candidates_with_similar_undifferentiated_results_select_nothing(): void
    {
        $selector = new StrategySelector;

        // Each candidate is better than the others on at least one criterion
        // and worse on at least one other: no dominance relationship exists.
        $a = $this->survivor('A', profitLossPercentage: '20', maxDrawdownPercentage: '10', winRate: '55', totalTrades: 20);
        $b = $this->survivor('B', profitLossPercentage: '15', maxDrawdownPercentage: '6', winRate: '58', totalTrades: 22);
        $c = $this->survivor('C', profitLossPercentage: '18', maxDrawdownPercentage: '8', winRate: '60', totalTrades: 18);

        $this->assertNull($selector->select(['A' => $a, 'B' => $b, 'C' => $c]));
    }

    public function test_higher_profit_with_much_higher_drawdown_is_not_auto_selected_when_dominated(): void
    {
        $selector = new StrategySelector;

        // A has the highest profit, but B matches or beats it on every other
        // criterion and has a far lower drawdown, so B dominates A.
        $highProfitHighRisk = $this->survivor('A', profitLossPercentage: '50', maxDrawdownPercentage: '40', winRate: '55', totalTrades: 20);
        $steadier = $this->survivor('B', profitLossPercentage: '50', maxDrawdownPercentage: '5', winRate: '55', totalTrades: 20);

        $this->assertSame($steadier->candidate, $selector->select(['A' => $highProfitHighRisk, 'B' => $steadier]));
    }

    /**
     * The Selector must decide on VALIDATION (out-of-sample) metrics, not
     * on the TRAIN metrics carried by StrategyCandidate->evaluation. Here
     * TRAIN clearly favors A (A dominates B on TRAIN) while VALIDATION
     * clearly favors B (B dominates A on VALIDATION): the winner must be B.
     */
    public function test_selection_follows_validation_metrics_even_when_train_metrics_favor_a_different_candidate(): void
    {
        $selector = new StrategySelector;

        $a = $this->survivorWithDistinctTrainAndValidation(
            'A',
            train: $this->metrics(profitLossPercentage: '50', maxDrawdownPercentage: '2', winRate: '80', totalTrades: 40),
            validation: $this->metrics(profitLossPercentage: '5', maxDrawdownPercentage: '20', winRate: '30', totalTrades: 5),
        );
        $b = $this->survivorWithDistinctTrainAndValidation(
            'B',
            train: $this->metrics(profitLossPercentage: '1', maxDrawdownPercentage: '30', winRate: '10', totalTrades: 2),
            validation: $this->metrics(profitLossPercentage: '40', maxDrawdownPercentage: '3', winRate: '75', totalTrades: 30),
        );

        $selected = $selector->select(['A' => $a, 'B' => $b]);

        $this->assertNotNull($selected);
        $this->assertSame('B', $selected->strategyName);
        $this->assertSame($b->candidate, $selected);
    }

    /**
     * profitFactor is not one of the Selector's four criteria. Two
     * candidates tied on every criterion the Selector does use, but with
     * wildly different profitFactor, must remain undecided (null) — proving
     * profitFactor plays no role in breaking the tie.
     */
    public function test_profit_factor_does_not_influence_the_selection(): void
    {
        $selector = new StrategySelector;

        $a = $this->survivor('A', profitLossPercentage: '20', maxDrawdownPercentage: '5', winRate: '60', totalTrades: 20, profitFactor: '100');
        $b = $this->survivor('B', profitLossPercentage: '20', maxDrawdownPercentage: '5', winRate: '60', totalTrades: 20, profitFactor: '0.01');

        $this->assertNull($selector->select(['A' => $a, 'B' => $b]));
    }

    private function survivor(
        string $strategyName,
        string $profitLossPercentage,
        string $maxDrawdownPercentage,
        string $winRate,
        int $totalTrades,
        string $profitFactor = '0',
    ): ValidationResult {
        $evaluation = $this->metrics($profitLossPercentage, $maxDrawdownPercentage, $winRate, $totalTrades, $profitFactor);

        return new ValidationResult(
            candidate: new StrategyCandidate($strategyName, $evaluation),
            validationEvaluation: $evaluation,
            passed: true,
        );
    }

    private function survivorWithDistinctTrainAndValidation(
        string $strategyName,
        StrategyEvaluation $train,
        StrategyEvaluation $validation,
    ): ValidationResult {
        return new ValidationResult(
            candidate: new StrategyCandidate($strategyName, $train),
            validationEvaluation: $validation,
            passed: true,
        );
    }

    private function metrics(
        string $profitLossPercentage,
        string $maxDrawdownPercentage,
        string $winRate,
        int $totalTrades,
        string $profitFactor = '0',
    ): StrategyEvaluation {
        return new StrategyEvaluation(
            initialCapital: '100',
            finalCapital: bcadd('100', $profitLossPercentage, 18),
            profitLoss: $profitLossPercentage,
            profitLossPercentage: $profitLossPercentage,
            totalTrades: $totalTrades,
            winningTrades: 0,
            losingTrades: 0,
            winRate: $winRate,
            totalProfit: '0',
            totalLoss: '0',
            maxDrawdownPercentage: $maxDrawdownPercentage,
            hasOpenPositionAtEnd: false,
            profitFactor: $profitFactor,
        );
    }
}
