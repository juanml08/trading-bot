<?php

namespace Tests\Unit;

use App\Strategy\StrategyCandidate;
use App\Strategy\StrategyEvaluation;
use App\Strategy\StrategySelector;
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

        $candidate = $this->candidate('A', profitLossPercentage: '10', maxDrawdownPercentage: '5', winRate: '60', totalTrades: 20);

        $this->assertSame($candidate, $selector->select(['A' => $candidate]));
    }

    public function test_a_candidate_that_clearly_dominates_another_is_selected(): void
    {
        $selector = new StrategySelector;

        $a = $this->candidate('A', profitLossPercentage: '20', maxDrawdownPercentage: '5', winRate: '65', totalTrades: 30);
        $b = $this->candidate('B', profitLossPercentage: '10', maxDrawdownPercentage: '8', winRate: '55', totalTrades: 20);

        $this->assertSame($a, $selector->select(['A' => $a, 'B' => $b]));
    }

    public function test_a_candidate_that_is_clearly_dominated_is_not_selected_in_favor_of_the_dominant_one(): void
    {
        $selector = new StrategySelector;

        $a = $this->candidate('A', profitLossPercentage: '5', maxDrawdownPercentage: '9', winRate: '52', totalTrades: 15);
        $b = $this->candidate('B', profitLossPercentage: '15', maxDrawdownPercentage: '4', winRate: '62', totalTrades: 25);

        $this->assertSame($b, $selector->select(['A' => $a, 'B' => $b]));
    }

    public function test_two_candidates_where_neither_dominates_the_other_select_nothing(): void
    {
        $selector = new StrategySelector;

        // A has better profit but worse drawdown than B: neither dominates.
        $a = $this->candidate('A', profitLossPercentage: '20', maxDrawdownPercentage: '15', winRate: '55', totalTrades: 20);
        $b = $this->candidate('B', profitLossPercentage: '10', maxDrawdownPercentage: '5', winRate: '55', totalTrades: 20);

        $this->assertNull($selector->select(['A' => $a, 'B' => $b]));
    }

    public function test_a_candidate_that_dominates_the_other_two_is_selected(): void
    {
        $selector = new StrategySelector;

        $a = $this->candidate('A', profitLossPercentage: '25', maxDrawdownPercentage: '4', winRate: '65', totalTrades: 30);
        $b = $this->candidate('B', profitLossPercentage: '15', maxDrawdownPercentage: '8', winRate: '55', totalTrades: 20);
        $c = $this->candidate('C', profitLossPercentage: '10', maxDrawdownPercentage: '10', winRate: '50', totalTrades: 18);

        $this->assertSame($a, $selector->select(['A' => $a, 'B' => $b, 'C' => $c]));
    }

    public function test_several_candidates_with_similar_undifferentiated_results_select_nothing(): void
    {
        $selector = new StrategySelector;

        // Each candidate is better than the others on at least one criterion
        // and worse on at least one other: no dominance relationship exists.
        $a = $this->candidate('A', profitLossPercentage: '20', maxDrawdownPercentage: '10', winRate: '55', totalTrades: 20);
        $b = $this->candidate('B', profitLossPercentage: '15', maxDrawdownPercentage: '6', winRate: '58', totalTrades: 22);
        $c = $this->candidate('C', profitLossPercentage: '18', maxDrawdownPercentage: '8', winRate: '60', totalTrades: 18);

        $this->assertNull($selector->select(['A' => $a, 'B' => $b, 'C' => $c]));
    }

    public function test_higher_profit_with_much_higher_drawdown_is_not_auto_selected_when_dominated(): void
    {
        $selector = new StrategySelector;

        // A has the highest profit, but B matches or beats it on every other
        // criterion and has a far lower drawdown, so B dominates A.
        $highProfitHighRisk = $this->candidate('A', profitLossPercentage: '50', maxDrawdownPercentage: '40', winRate: '55', totalTrades: 20);
        $steadier = $this->candidate('B', profitLossPercentage: '50', maxDrawdownPercentage: '5', winRate: '55', totalTrades: 20);

        $this->assertSame($steadier, $selector->select(['A' => $highProfitHighRisk, 'B' => $steadier]));
    }

    private function candidate(
        string $strategyName,
        string $profitLossPercentage,
        string $maxDrawdownPercentage,
        string $winRate,
        int $totalTrades,
    ): StrategyCandidate {
        return new StrategyCandidate(
            strategyName: $strategyName,
            evaluation: new StrategyEvaluation(
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
            ),
        );
    }
}
