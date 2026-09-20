<?php

namespace Tests\Unit;

use App\MarketData\Candle;
use App\MarketData\Timeframe;
use App\Strategy\Signal;
use App\Strategy\SignalType;
use App\Strategy\Strategy;
use App\Strategy\StrategyEvaluation;
use App\Strategy\StrategyEvaluator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class StrategyEvaluatorTest extends TestCase
{
    public function test_a_strategy_with_no_operations_leaves_capital_unchanged(): void
    {
        $evaluation = $this->evaluate(['100', '101', '102'], [
            SignalType::HOLD,
            SignalType::HOLD,
            SignalType::HOLD,
        ], '1000');

        $this->assertSame('1000', $evaluation->finalCapital);
        $this->assertSame(bcsub('1000', '1000', 18), $evaluation->profitLoss);
        $this->assertSame(0, $evaluation->totalTrades);
        $this->assertSame(0, $evaluation->winningTrades);
        $this->assertSame(0, $evaluation->losingTrades);
        $this->assertSame('0', $evaluation->winRate);
        $this->assertFalse($evaluation->hasOpenPositionAtEnd);
    }

    public function test_a_single_winning_operation_increases_capital(): void
    {
        $evaluation = $this->evaluate(['100', '150'], [
            SignalType::BUY,
            SignalType::SELL,
        ], '100');

        $this->assertSame('150.000000000000000000', $evaluation->finalCapital);
        $this->assertSame('50.000000000000000000', $evaluation->profitLoss);
        $this->assertSame('50.000000000000000000', $evaluation->profitLossPercentage);
        $this->assertSame(1, $evaluation->totalTrades);
        $this->assertSame(1, $evaluation->winningTrades);
        $this->assertSame(0, $evaluation->losingTrades);
        $this->assertSame('50.000000000000000000', $evaluation->totalProfit);
        $this->assertSame('0', $evaluation->totalLoss);
        $this->assertFalse($evaluation->hasOpenPositionAtEnd);
    }

    public function test_a_single_losing_operation_decreases_capital(): void
    {
        $evaluation = $this->evaluate(['100', '50'], [
            SignalType::BUY,
            SignalType::SELL,
        ], '100');

        $this->assertSame('50.000000000000000000', $evaluation->finalCapital);
        $this->assertSame('-50.000000000000000000', $evaluation->profitLoss);
        $this->assertSame('-50.000000000000000000', $evaluation->profitLossPercentage);
        $this->assertSame(1, $evaluation->totalTrades);
        $this->assertSame(0, $evaluation->winningTrades);
        $this->assertSame(1, $evaluation->losingTrades);
        $this->assertSame('0', $evaluation->totalProfit);
        $this->assertSame('50.000000000000000000', $evaluation->totalLoss);
        $this->assertFalse($evaluation->hasOpenPositionAtEnd);
    }

    public function test_several_operations_are_aggregated_correctly(): void
    {
        $evaluation = $this->evaluate(['100', '150', '150', '100'], [
            SignalType::BUY,
            SignalType::SELL,
            SignalType::BUY,
            SignalType::SELL,
        ], '100');

        $this->assertSame(2, $evaluation->totalTrades);
        $this->assertSame(1, $evaluation->winningTrades);
        $this->assertSame(1, $evaluation->losingTrades);
        $this->assertSame(bcadd('50', '0', 18), $evaluation->totalProfit);
        $this->assertSame(bcadd('50', '0', 18), $evaluation->totalLoss);
        $this->assertSame(bcsub('100', '100', 18), $evaluation->profitLoss);
    }

    public function test_win_rate_is_calculated_correctly(): void
    {
        $evaluation = $this->evaluate(['100', '150', '150', '100'], [
            SignalType::BUY,
            SignalType::SELL,
            SignalType::BUY,
            SignalType::SELL,
        ], '100');

        $this->assertSame(bcmul(bcdiv('1', '2', 18), '100', 18), $evaluation->winRate);
    }

    public function test_max_drawdown_is_calculated_from_the_equity_curve(): void
    {
        $evaluation = $this->evaluate(['100', '200', '100', '100'], [
            SignalType::BUY,
            SignalType::HOLD,
            SignalType::HOLD,
            SignalType::SELL,
        ], '100');

        $expectedMaxDrawdown = bcmul(bcdiv(bcsub('200', '100', 18), '200', 18), '100', 18);

        $this->assertSame($expectedMaxDrawdown, $evaluation->maxDrawdownPercentage);
    }

    public function test_a_position_left_open_at_the_end_is_not_sold_or_counted_as_a_trade(): void
    {
        $evaluation = $this->evaluate(['100', '110'], [
            SignalType::BUY,
            SignalType::HOLD,
        ], '100');

        $this->assertTrue($evaluation->hasOpenPositionAtEnd);
        $this->assertSame(bcdiv('100', '100', 18), $evaluation->openPositionQuantity);
        $this->assertSame('100', $evaluation->openPositionEntryPrice);
        $this->assertSame(0, $evaluation->totalTrades);
        $this->assertSame(0, $evaluation->winningTrades);
        $this->assertSame(0, $evaluation->losingTrades);
        $this->assertSame(bcadd('0', bcmul(bcdiv('100', '100', 18), '110', 18), 18), $evaluation->finalCapital);
    }

    public function test_a_zero_result_trade_does_not_count_as_winning_or_losing(): void
    {
        $evaluation = $this->evaluateTrades(['0']);

        $this->assertSame(1, $evaluation->totalTrades);
        $this->assertSame(0, $evaluation->winningTrades);
        $this->assertSame(0, $evaluation->losingTrades);
    }

    public function test_profit_loss_percentage_is_zero_when_initial_capital_is_zero(): void
    {
        $evaluation = $this->evaluate(['100', '101', '102'], [
            SignalType::HOLD,
            SignalType::HOLD,
            SignalType::HOLD,
        ], '0');

        $this->assertSame('0', $evaluation->profitLossPercentage);
    }

    public function test_a_commission_reduces_the_net_result_of_a_winning_operation(): void
    {
        $withoutCommission = $this->evaluate(['100', '150'], [
            SignalType::BUY,
            SignalType::SELL,
        ], '100');

        $withCommission = $this->evaluate(['100', '150'], [
            SignalType::BUY,
            SignalType::SELL,
        ], '100', commissionRate: '0.01');

        $this->assertTrue(bccomp($withCommission->profitLoss, $withoutCommission->profitLoss, 18) < 0);
        $this->assertSame(0, bccomp($withCommission->grossProfitLoss, $withoutCommission->profitLoss, 18));
        $this->assertTrue(bccomp($withCommission->totalCosts, '0', 18) > 0);
    }

    public function test_slippage_adjusts_the_effective_execution_price(): void
    {
        $evaluation = $this->evaluate(['100', '110'], [
            SignalType::BUY,
            SignalType::HOLD,
        ], '100', slippageRate: '0.01');

        // A buyer pays a worse (higher) price than the raw candle close.
        $this->assertSame(bcmul('100', '1.01', 18), $evaluation->openPositionEntryPrice);
        $this->assertSame(bcdiv('100', bcmul('100', '1.01', 18), 18), $evaluation->openPositionQuantity);
    }

    public function test_commission_and_slippage_apply_together(): void
    {
        $withoutCosts = $this->evaluate(['100', '150'], [
            SignalType::BUY,
            SignalType::SELL,
        ], '100');

        $withBothCosts = $this->evaluate(['100', '150'], [
            SignalType::BUY,
            SignalType::SELL,
        ], '100', commissionRate: '0.01', slippageRate: '0.01');

        $this->assertTrue(bccomp($withBothCosts->profitLoss, $withoutCosts->profitLoss, 18) < 0);
        $this->assertTrue(bccomp($withBothCosts->grossProfitLoss, $withBothCosts->profitLoss, 18) > 0);
        $this->assertSame(0, bccomp($withBothCosts->grossProfitLoss, $withoutCosts->profitLoss, 18));
    }

    public function test_a_strategy_profitable_before_costs_can_become_unprofitable_after_costs(): void
    {
        // A thin 1% gross gain is wiped out by a combined 3% round-trip cost.
        $evaluation = $this->evaluate(['100', '101'], [
            SignalType::BUY,
            SignalType::SELL,
        ], '100', commissionRate: '0.015', slippageRate: '0.015');

        $this->assertTrue(bccomp($evaluation->grossProfitLoss, '0', 18) > 0);
        $this->assertTrue(bccomp($evaluation->profitLoss, '0', 18) < 0);
    }

    public function test_financial_calculations_remain_strings_with_no_costs_configured(): void
    {
        $evaluation = $this->evaluate(['100', '150'], [
            SignalType::BUY,
            SignalType::SELL,
        ], '100');

        $this->assertIsString($evaluation->profitLoss);
        $this->assertIsString($evaluation->grossProfitLoss);
        $this->assertIsString($evaluation->totalCosts);
        $this->assertSame(0, bccomp($evaluation->totalCosts, '0', 18));
        $this->assertSame($evaluation->profitLoss, $evaluation->grossProfitLoss);
    }

    public function test_profit_factor_aggregates_several_winning_and_losing_trades(): void
    {
        // +$4, +$2, -$3, -$1 => profitFactor = 6 / 4 = 1.5
        $evaluation = $this->evaluateTrades(['4', '2', '-3', '-1']);

        $this->assertSame(0, bccomp('1.5', $evaluation->profitFactor, 18));
    }

    public function test_high_win_rate_can_still_have_a_profit_factor_below_one(): void
    {
        $trades = array_merge(array_fill(0, 9, '0.10'), ['-2.00']);

        $evaluation = $this->evaluateTrades($trades);

        $this->assertSame(90, (int) round((float) $evaluation->winRate));
        $this->assertSame(0, bccomp('0.45', $evaluation->profitFactor, 18));
    }

    public function test_profit_factor_is_zero_when_all_trades_are_losing(): void
    {
        $evaluation = $this->evaluateTrades(['-1', '-2', '-3']);

        $this->assertSame(0, bccomp('0', $evaluation->profitFactor, 18));
    }

    public function test_profit_factor_is_infinite_when_there_are_no_losing_trades(): void
    {
        $evaluation = $this->evaluateTrades(['1', '2', '3']);

        $this->assertSame('INF', $evaluation->profitFactor);
    }

    public function test_zero_result_trades_do_not_affect_profit_factor(): void
    {
        $evaluation = $this->evaluateTrades(['4', '0', '-2']);

        $this->assertSame(0, bccomp('2.0', $evaluation->profitFactor, 18));
    }

    public function test_profit_factor_is_zero_with_no_closed_trades(): void
    {
        $evaluation = $this->evaluate(['100', '101', '102'], [
            SignalType::HOLD,
            SignalType::HOLD,
            SignalType::HOLD,
        ], '1000');

        $this->assertSame('0', $evaluation->profitFactor);
    }

    public function test_profit_factor_does_not_change_any_existing_metric(): void
    {
        $withoutProfitFactorContext = $this->evaluate(['100', '150', '150', '100'], [
            SignalType::BUY,
            SignalType::SELL,
            SignalType::BUY,
            SignalType::SELL,
        ], '100', commissionRate: '0.01', slippageRate: '0.01');

        $repeat = $this->evaluate(['100', '150', '150', '100'], [
            SignalType::BUY,
            SignalType::SELL,
            SignalType::BUY,
            SignalType::SELL,
        ], '100', commissionRate: '0.01', slippageRate: '0.01');

        $this->assertSame($withoutProfitFactorContext->initialCapital, $repeat->initialCapital);
        $this->assertSame($withoutProfitFactorContext->finalCapital, $repeat->finalCapital);
        $this->assertSame($withoutProfitFactorContext->profitLoss, $repeat->profitLoss);
        $this->assertSame($withoutProfitFactorContext->profitLossPercentage, $repeat->profitLossPercentage);
        $this->assertSame($withoutProfitFactorContext->totalTrades, $repeat->totalTrades);
        $this->assertSame($withoutProfitFactorContext->winningTrades, $repeat->winningTrades);
        $this->assertSame($withoutProfitFactorContext->losingTrades, $repeat->losingTrades);
        $this->assertSame($withoutProfitFactorContext->winRate, $repeat->winRate);
        $this->assertSame($withoutProfitFactorContext->totalProfit, $repeat->totalProfit);
        $this->assertSame($withoutProfitFactorContext->totalLoss, $repeat->totalLoss);
        $this->assertSame($withoutProfitFactorContext->maxDrawdownPercentage, $repeat->maxDrawdownPercentage);
        $this->assertSame($withoutProfitFactorContext->hasOpenPositionAtEnd, $repeat->hasOpenPositionAtEnd);
        $this->assertSame($withoutProfitFactorContext->openPositionQuantity, $repeat->openPositionQuantity);
        $this->assertSame($withoutProfitFactorContext->openPositionEntryPrice, $repeat->openPositionEntryPrice);
        $this->assertSame($withoutProfitFactorContext->grossProfitLoss, $repeat->grossProfitLoss);
        $this->assertSame($withoutProfitFactorContext->totalCosts, $repeat->totalCosts);
    }

    /**
     * Builds a chained sequence of closed trades with exact profit/loss
     * amounts, by moving the close price by each requested amount and
     * alternating BUY/SELL signals so every position uses the full
     * available cash (quantity always 1, since entry price equals the
     * current cash balance).
     *
     * @param  string[]  $tradeProfitLosses  desired P&L of each trade, in order
     */
    private function evaluateTrades(array $tradeProfitLosses): StrategyEvaluation
    {
        $initialCapital = '100';
        $price = $initialCapital;
        $closes = [$price];
        $signals = [SignalType::BUY];

        foreach ($tradeProfitLosses as $index => $pnl) {
            $price = bcadd($price, $pnl, 18);
            $closes[] = $price;
            $signals[] = SignalType::SELL;

            if ($index < count($tradeProfitLosses) - 1) {
                $closes[] = $price;
                $signals[] = SignalType::BUY;
            }
        }

        return $this->evaluate($closes, $signals, $initialCapital);
    }

    /**
     * @param  string[]  $closes
     * @param  SignalType[]  $signals
     */
    private function evaluate(
        array $closes,
        array $signals,
        string $initialCapital,
        string $commissionRate = '0',
        string $slippageRate = '0',
    ): StrategyEvaluation {
        $candles = array_map(
            fn (string $close, int $index) => new Candle(
                symbol: 'BTCUSDT',
                timeframe: Timeframe::Hour1,
                timestamp: CarbonImmutable::now()->addHours($index),
                open: $close,
                high: $close,
                low: $close,
                close: $close,
                volume: '1',
            ),
            $closes,
            array_keys($closes),
        );

        $strategy = new ScriptedStrategy($signals);

        return (new StrategyEvaluator($commissionRate, $slippageRate))->evaluate($strategy, $candles, $initialCapital);
    }
}

/**
 * Test double that returns a pre-scripted sequence of signals, one per
 * call to generate(), regardless of the candles it receives.
 */
final class ScriptedStrategy implements Strategy
{
    /**
     * @param  SignalType[]  $signals
     */
    public function __construct(private array $signals) {}

    public function generate(array $candles): Signal
    {
        $type = array_shift($this->signals) ?? SignalType::HOLD;

        return new Signal(
            type: $type,
            reason: 'scripted signal for test',
            generatedAt: CarbonImmutable::now(),
        );
    }
}
