<?php

namespace Tests\Unit;

use App\Strategy\StrategyDiscovery;
use App\Strategy\StrategyEvaluation;
use PHPUnit\Framework\TestCase;

class StrategyDiscoveryTest extends TestCase
{
    public function test_an_empty_list_of_evaluations_yields_no_candidates(): void
    {
        $discovery = $this->discoveryWithDefaultCriteria();

        $this->assertSame([], $discovery->discover([]));
    }

    public function test_a_strategy_meeting_every_minimum_exactly_is_a_candidate(): void
    {
        $discovery = $this->discoveryWithDefaultCriteria();

        $evaluation = $this->evaluation(totalTrades: 20, winRate: '60', maxDrawdownPercentage: '10', profitLoss: '2');

        $candidates = $discovery->discover(['A' => $evaluation]);

        $this->assertArrayHasKey('A', $candidates);
        $this->assertSame('A', $candidates['A']->strategyName);
        $this->assertSame($evaluation, $candidates['A']->evaluation);
    }

    public function test_a_strategy_below_the_minimum_trades_does_not_qualify(): void
    {
        $discovery = $this->discoveryWithDefaultCriteria();

        $evaluation = $this->evaluation(totalTrades: 19, winRate: '60', maxDrawdownPercentage: '10', profitLoss: '2');

        $this->assertSame([], $discovery->discover(['A' => $evaluation]));
    }

    public function test_a_strategy_below_the_minimum_win_rate_does_not_qualify(): void
    {
        $discovery = $this->discoveryWithDefaultCriteria();

        $evaluation = $this->evaluation(totalTrades: 30, winRate: '45', maxDrawdownPercentage: '4', profitLoss: '3');

        $this->assertSame([], $discovery->discover(['B' => $evaluation]));
    }

    public function test_a_strategy_above_the_maximum_drawdown_does_not_qualify(): void
    {
        $discovery = $this->discoveryWithDefaultCriteria();

        $evaluation = $this->evaluation(totalTrades: 15, winRate: '58', maxDrawdownPercentage: '12', profitLoss: '4');

        $this->assertSame([], $discovery->discover(['C' => $evaluation]));
    }

    public function test_a_strategy_below_the_minimum_profit_loss_does_not_qualify(): void
    {
        $discovery = $this->discoveryWithDefaultCriteria();

        $evaluation = $this->evaluation(totalTrades: 20, winRate: '60', maxDrawdownPercentage: '5', profitLoss: '0.5');

        $this->assertSame([], $discovery->discover(['D' => $evaluation]));
    }

    public function test_several_qualifying_strategies_all_pass_as_candidates(): void
    {
        $discovery = $this->discoveryWithDefaultCriteria();

        $evaluationA = $this->evaluation(totalTrades: 20, winRate: '60', maxDrawdownPercentage: '5', profitLoss: '2');
        $evaluationB = $this->evaluation(totalTrades: 25, winRate: '70', maxDrawdownPercentage: '3', profitLoss: '10');

        $candidates = $discovery->discover(['A' => $evaluationA, 'B' => $evaluationB]);

        $this->assertSame(['A', 'B'], array_keys($candidates));
    }

    public function test_no_qualifying_strategies_yields_no_candidates(): void
    {
        $discovery = $this->discoveryWithDefaultCriteria();

        $evaluationB = $this->evaluation(totalTrades: 30, winRate: '45', maxDrawdownPercentage: '4', profitLoss: '3');
        $evaluationC = $this->evaluation(totalTrades: 15, winRate: '58', maxDrawdownPercentage: '12', profitLoss: '4');

        $this->assertSame([], $discovery->discover(['B' => $evaluationB, 'C' => $evaluationC]));
    }

    private function discoveryWithDefaultCriteria(): StrategyDiscovery
    {
        return new StrategyDiscovery(
            minimumTrades: 20,
            minimumWinRate: '60',
            maximumDrawdown: '10',
            minimumProfitLoss: '1',
        );
    }

    private function evaluation(
        int $totalTrades,
        string $winRate,
        string $maxDrawdownPercentage,
        string $profitLoss,
    ): StrategyEvaluation {
        return new StrategyEvaluation(
            initialCapital: '100',
            finalCapital: bcadd('100', $profitLoss, 18),
            profitLoss: $profitLoss,
            profitLossPercentage: bcmul(bcdiv($profitLoss, '100', 18), '100', 18),
            totalTrades: $totalTrades,
            winningTrades: 0,
            losingTrades: 0,
            winRate: $winRate,
            totalProfit: '0',
            totalLoss: '0',
            maxDrawdownPercentage: $maxDrawdownPercentage,
            hasOpenPositionAtEnd: false,
        );
    }
}
