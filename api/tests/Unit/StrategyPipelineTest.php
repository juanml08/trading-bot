<?php

namespace Tests\Unit;

use App\MarketData\Candle;
use App\MarketData\Timeframe;
use App\Strategy\Signal;
use App\Strategy\SignalType;
use App\Strategy\Strategy;
use App\Strategy\StrategyEvaluator;
use App\Strategy\StrategyPipeline;
use App\Strategy\StrategySelector;
use App\Strategy\TrainValidationSplit;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class StrategyPipelineTest extends TestCase
{
    /**
     * One combined run covering: every strategy is evaluated on TRAIN; a
     * strategy that fails TRAIN Discovery (B) never reaches VALIDATION and
     * produces no ValidationResult; a strategy that passes TRAIN but fails
     * VALIDATION (C) is excluded from the Selector while its ValidationResult
     * (with its exact failed criteria) is preserved; a strategy that passes
     * both (A) is the only one handed to the Selector and gets selected;
     * TRAIN and VALIDATION evaluations remain independent objects.
     */
    public function test_the_full_pipeline_routes_each_strategy_correctly(): void
    {
        // TRAIN: 8 rising candles, shared by every strategy.
        $trainCloses = ['100', '101', '102', '103', '104', '105', '106', '107'];
        // VALIDATION: 8 oscillating candles, shared by every strategy.
        $validationCloses = ['300', '305', '300', '295', '300', '305', '300', '295'];
        $candles = $this->candles([...$trainCloses, ...$validationCloses]);

        // A: buys low/sells high on both windows -> profitable on TRAIN and VALIDATION.
        $strategyA = new PositionalSignalStrategy([1 => SignalType::BUY, 2 => SignalType::SELL, 4 => SignalType::BUY, 6 => SignalType::SELL]);
        // B: never signals -> 0 trades on TRAIN -> fails Discovery, never reaches VALIDATION.
        $strategyB = new PositionalSignalStrategy([]);
        // C: profitable on the rising TRAIN window, but its timing buys high/sells low on the oscillating VALIDATION window -> losses there.
        $strategyC = new PositionalSignalStrategy([2 => SignalType::BUY, 3 => SignalType::SELL, 6 => SignalType::BUY, 7 => SignalType::SELL]);

        $pipeline = $this->pipeline(
            minimumTrades: 2,
            minimumWinRate: '50',
            maximumDrawdown: '100',
            minimumProfitLoss: '0',
            trainPercentage: 50,
        );

        $result = $pipeline->run(['A' => $strategyA, 'B' => $strategyB, 'C' => $strategyC], $candles, '1000');

        // Only A and C passed TRAIN Discovery, so only they produced a ValidationResult.
        $this->assertCount(2, $result->validationResults);
        $byStrategyName = [];
        foreach ($result->validationResults as $validationResult) {
            $byStrategyName[$validationResult->candidate->strategyName] = $validationResult;
        }
        $this->assertArrayHasKey('A', $byStrategyName);
        $this->assertArrayHasKey('C', $byStrategyName);
        $this->assertArrayNotHasKey('B', $byStrategyName);

        // A passed VALIDATION.
        $this->assertTrue($byStrategyName['A']->passed);
        $this->assertSame([], $byStrategyName['A']->failedCriteria);

        // C passed TRAIN but failed VALIDATION on exactly these two criteria.
        $this->assertFalse($byStrategyName['C']->passed);
        $this->assertSame(['minimumWinRate', 'minimumProfitLoss'], $byStrategyName['C']->failedCriteria);

        // TRAIN and VALIDATION evaluations are independent objects for C.
        $this->assertNotSame($byStrategyName['C']->candidate->evaluation, $byStrategyName['C']->validationEvaluation);
        $this->assertTrue(bccomp($byStrategyName['C']->candidate->evaluation->profitLoss, '0', 18) > 0);
        $this->assertTrue(bccomp($byStrategyName['C']->validationEvaluation->profitLoss, '0', 18) < 0);

        // Only A reached the Selector (alone), so A is selected.
        $this->assertNotNull($result->selectedCandidate);
        $this->assertSame('A', $result->selectedCandidate->strategyName);

        // discoveryResults covers every strategy handed to the pipeline —
        // including B, which never reached VALIDATION — with the exact TRAIN
        // evaluation each one produced.
        $this->assertSame(['A', 'B', 'C'], array_keys($result->discoveryResults));

        $this->assertTrue($result->discoveryResults['A']->passed);
        $this->assertSame([], $result->discoveryResults['A']->failedCriteria);

        $this->assertFalse($result->discoveryResults['B']->passed);
        $this->assertSame(['minimumTrades', 'minimumWinRate'], $result->discoveryResults['B']->failedCriteria);
        $this->assertSame(0, $result->discoveryResults['B']->trainEvaluation->totalTrades);

        $this->assertTrue($result->discoveryResults['C']->passed);
        $this->assertSame([], $result->discoveryResults['C']->failedCriteria);
        // C's TRAIN evaluation is the same profitable one its StrategyCandidate carries.
        $this->assertSame($byStrategyName['C']->candidate->evaluation, $result->discoveryResults['C']->trainEvaluation);
    }

    public function test_no_strategies_yields_no_validation_results_and_no_selection(): void
    {
        $pipeline = $this->pipeline(minimumTrades: 1, minimumWinRate: '0', maximumDrawdown: '100', minimumProfitLoss: '-1000000', trainPercentage: 50);

        $result = $pipeline->run([], $this->candles(['100', '101', '102', '103']), '1000');

        $this->assertSame([], $result->validationResults);
        $this->assertNull($result->selectedCandidate);
    }

    public function test_a_strategy_that_fails_train_never_produces_a_validation_result(): void
    {
        $pipeline = $this->pipeline(minimumTrades: 1, minimumWinRate: '0', maximumDrawdown: '100', minimumProfitLoss: '-1000000', trainPercentage: 50);
        $neverTrades = new PositionalSignalStrategy([]);

        $result = $pipeline->run(['Idle' => $neverTrades], $this->candles(['100', '101', '102', '103']), '1000');

        $this->assertSame([], $result->validationResults);
        $this->assertNull($result->selectedCandidate);
    }

    public function test_insufficient_validation_sample_fails_specifically_on_minimum_trades(): void
    {
        // TRAIN: 8 candles, enough for the strategy to close one trade (buy@2, sell@5).
        $trainCloses = ['100', '101', '102', '103', '104', '105', '106', '107'];
        // VALIDATION: only 2 candles, not enough to reach the sell signal (@5) -> the position opens and never closes.
        $validationCloses = ['500', '501'];
        $candles = $this->candles([...$trainCloses, ...$validationCloses]);

        $strategy = new PositionalSignalStrategy([2 => SignalType::BUY, 5 => SignalType::SELL]);

        $pipeline = $this->pipeline(minimumTrades: 1, minimumWinRate: '0', maximumDrawdown: '100', minimumProfitLoss: '-1000000', trainPercentage: 80);

        $result = $pipeline->run(['Strategy' => $strategy], $candles, '1000');

        $this->assertCount(1, $result->validationResults);
        $validationResult = $result->validationResults[0];

        $this->assertFalse($validationResult->passed);
        $this->assertSame(['minimumTrades'], $validationResult->failedCriteria);
        $this->assertSame(0, $validationResult->validationEvaluation->totalTrades);
        $this->assertNull($result->selectedCandidate);
    }

    public function test_several_candidates_passing_validation_with_no_clear_winner_yields_no_selection(): void
    {
        $trainCloses = ['100', '101', '102', '103', '104', '105', '106', '107'];
        $validationCloses = ['300', '301', '302', '303', '304', '305', '306', '307'];
        $candles = $this->candles([...$trainCloses, ...$validationCloses]);

        // Two independently constructed strategies with identical behaviour and
        // fed the identical candles produce tied metrics: neither dominates the
        // other, so StrategySelector's existing ambiguity rule applies.
        $signals = [1 => SignalType::BUY, 2 => SignalType::SELL, 4 => SignalType::BUY, 6 => SignalType::SELL];
        $strategyOne = new PositionalSignalStrategy($signals);
        $strategyTwo = new PositionalSignalStrategy($signals);

        $pipeline = $this->pipeline(minimumTrades: 2, minimumWinRate: '50', maximumDrawdown: '100', minimumProfitLoss: '0', trainPercentage: 50);

        $result = $pipeline->run(['One' => $strategyOne, 'Two' => $strategyTwo], $candles, '1000');

        $this->assertCount(2, $result->validationResults);
        $this->assertTrue($result->validationResults[0]->passed);
        $this->assertTrue($result->validationResults[1]->passed);
        $this->assertNull($result->selectedCandidate);
    }

    public function test_validation_fails_specifically_on_maximum_drawdown_while_meeting_every_other_criterion(): void
    {
        // TRAIN: buys and sells on a steady rise, comfortably clearing every
        // criterion with no drawdown at all.
        $trainCloses = ['100', '101', '102', '103', '104', '105', '106', '107'];
        // VALIDATION: buys, then the price dips 30% before recovering enough
        // to sell at a profit. The trade still closes as a single winner
        // (minimumTrades, minimumWinRate, minimumProfitLoss all satisfied),
        // but the drawdown incurred while the position was open breaches
        // maximumDrawdown.
        $validationCloses = ['200', '200', '140', '220'];
        $candles = $this->candles([...$trainCloses, ...$validationCloses]);

        $strategy = new PositionalSignalStrategy([2 => SignalType::BUY, 4 => SignalType::SELL]);

        $pipeline = $this->pipeline(minimumTrades: 1, minimumWinRate: '50', maximumDrawdown: '10', minimumProfitLoss: '0', trainPercentage: 70);

        $result = $pipeline->run(['Volatile' => $strategy], $candles, '1000');

        $this->assertCount(1, $result->validationResults);
        $validationResult = $result->validationResults[0];

        $this->assertFalse($validationResult->passed);
        $this->assertSame(['maximumDrawdown'], $validationResult->failedCriteria);
        $this->assertNull($result->selectedCandidate);
    }

    public function test_validation_passes_when_every_metric_exactly_equals_its_threshold(): void
    {
        // TRAIN: same buy/sell timing, comfortably clears every criterion.
        $trainCloses = ['100', '100', '160'];
        // VALIDATION: one winning trade whose resulting metrics land exactly
        // on every threshold (totalTrades = minimumTrades, winRate =
        // minimumWinRate, maxDrawdownPercentage = maximumDrawdown, profitLoss
        // = minimumProfitLoss). Since every criterion is inclusive (>=, >=,
        // <=, >=), matching a threshold exactly must still be a PASS.
        $validationCloses = ['100', '100', '150'];
        $candles = $this->candles([...$trainCloses, ...$validationCloses]);

        $strategy = new PositionalSignalStrategy([2 => SignalType::BUY, 3 => SignalType::SELL]);

        $pipeline = $this->pipeline(minimumTrades: 1, minimumWinRate: '100', maximumDrawdown: '0', minimumProfitLoss: '50', trainPercentage: 50);

        $result = $pipeline->run(['Boundary' => $strategy], $candles, '100');

        $this->assertCount(1, $result->validationResults);
        $validationResult = $result->validationResults[0];

        $this->assertTrue($validationResult->passed);
        $this->assertSame([], $validationResult->failedCriteria);
    }

    private function pipeline(
        int $minimumTrades,
        string $minimumWinRate,
        string $maximumDrawdown,
        string $minimumProfitLoss,
        int $trainPercentage,
    ): StrategyPipeline {
        return new StrategyPipeline(
            evaluator: new StrategyEvaluator,
            selector: new StrategySelector,
            split: new TrainValidationSplit($trainPercentage),
            minimumTrades: $minimumTrades,
            minimumWinRate: $minimumWinRate,
            maximumDrawdown: $maximumDrawdown,
            minimumProfitLoss: $minimumProfitLoss,
        );
    }

    /**
     * @param  string[]  $closes
     * @return Candle[]
     */
    private function candles(array $closes): array
    {
        $base = CarbonImmutable::parse('2024-01-01 00:00:00');

        return array_map(
            fn (string $close, int $index): Candle => new Candle(
                symbol: 'BTCUSDT',
                timeframe: Timeframe::Hour1,
                timestamp: $base->addHours($index),
                open: $close,
                high: $close,
                low: $close,
                close: $close,
                volume: '1',
            ),
            $closes,
            array_keys($closes),
        );
    }
}

/**
 * Test double that returns a fixed signal based purely on how many candles
 * it has been given so far (`count($candles)`), defaulting to HOLD. Because
 * it carries no mutable state, the same instance can safely be evaluated
 * twice (once over TRAIN candles, once over VALIDATION candles) — each
 * evaluate() call starts counting from 1 again, so both runs replay the
 * same positional pattern independently.
 */
final class PositionalSignalStrategy implements Strategy
{
    /**
     * @param  array<int, SignalType>  $signalsByCandleCount
     */
    public function __construct(private readonly array $signalsByCandleCount) {}

    public function generate(array $candles): Signal
    {
        $type = $this->signalsByCandleCount[count($candles)] ?? SignalType::HOLD;

        return new Signal(
            type: $type,
            reason: 'positional test signal',
            generatedAt: CarbonImmutable::now(),
        );
    }
}
