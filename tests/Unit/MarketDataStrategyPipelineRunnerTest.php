<?php

namespace Tests\Unit;

use App\MarketData\Candle;
use App\MarketData\MarketDataProvider;
use App\MarketData\Timeframe;
use App\Orchestration\MarketDataStrategyPipelineRunner;
use App\Strategy\Signal;
use App\Strategy\SignalType;
use App\Strategy\Strategy;
use App\Strategy\StrategyEvaluator;
use App\Strategy\StrategyPipeline;
use App\Strategy\StrategySelector;
use App\Strategy\TrainValidationSplit;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MarketDataStrategyPipelineRunnerTest extends TestCase
{
    public function test_it_requests_candles_from_the_provider_with_the_given_parameters(): void
    {
        $provider = new RecordingMarketDataProvider([]);
        $runner = $this->runner($provider, minimumTrades: 0, minimumWinRate: '0', maximumDrawdown: '100', minimumProfitLoss: '-1000000', trainPercentage: 50);

        $symbol = 'ETHUSDT';
        $timeframe = Timeframe::Hour4;
        $from = CarbonImmutable::parse('2024-01-01 00:00:00');
        $to = CarbonImmutable::parse('2024-02-01 00:00:00');

        $runner->run($symbol, $timeframe, $from, $to, [], '1000');

        $this->assertSame($symbol, $provider->requestedSymbol);
        $this->assertSame($timeframe, $provider->requestedTimeframe);
        $this->assertSame($from, $provider->requestedFrom);
        $this->assertSame($to, $provider->requestedTo);
    }

    public function test_it_forwards_the_providers_candles_to_the_pipeline_unchanged(): void
    {
        // 8 TRAIN + 4 VALIDATION candles, oldest first, distinguishable by close.
        $candles = $this->candles(['100', '101', '102', '103', '104', '105', '106', '107', '200', '201', '202', '203']);
        $provider = new RecordingMarketDataProvider($candles);

        $spy = new CandleCapturingStrategy;
        $runner = $this->runner($provider, minimumTrades: 0, minimumWinRate: '0', maximumDrawdown: '100', minimumProfitLoss: '-1000000', trainPercentage: 67);

        $result = $runner->run('BTCUSDT', Timeframe::Hour1, CarbonImmutable::now(), CarbonImmutable::now(), ['Spy' => $spy], '1000');

        // The strategy passed TRAIN Discovery (0 trades still meets every
        // relaxed threshold above) and therefore was evaluated again on
        // VALIDATION, so both windows were captured.
        $this->assertCount(1, $result->validationResults);
        $this->assertCount(2, $spy->windows);

        $expectedTrain = array_slice($candles, 0, 8);
        $expectedValidation = array_slice($candles, 8);

        $this->assertSame($expectedTrain, $spy->windows[0]);
        $this->assertSame($expectedValidation, $spy->windows[1]);
    }

    public function test_it_forwards_strategies_and_initial_capital_to_the_pipeline_unchanged(): void
    {
        $candles = $this->candles(['100', '101', '102', '103', '104', '105', '106', '107']);
        $provider = new RecordingMarketDataProvider($candles);

        // Never trades, so it is guaranteed to reach VALIDATION under these
        // relaxed thresholds regardless of the candles' actual prices.
        $idle = new IdleStrategy;
        $runner = $this->runner($provider, minimumTrades: 0, minimumWinRate: '0', maximumDrawdown: '100', minimumProfitLoss: '-1000000', trainPercentage: 50);

        $result = $runner->run('BTCUSDT', Timeframe::Hour1, CarbonImmutable::now(), CarbonImmutable::now(), ['Idle' => $idle], '12345');

        $this->assertCount(1, $result->validationResults);
        $this->assertSame('Idle', $result->validationResults[0]->candidate->strategyName);
        $this->assertSame('12345', $result->validationResults[0]->candidate->evaluation->initialCapital);
        $this->assertSame('12345', $result->validationResults[0]->validationEvaluation->initialCapital);
    }

    public function test_it_returns_exactly_what_the_pipeline_produces_for_the_same_inputs(): void
    {
        // StrategyPipeline is final and cannot be doubled, so identity is
        // demonstrated by equivalence: running the pipeline directly against
        // the provider's candles must yield the same outcome as running it
        // through the runner, since the runner performs no transformation.
        $candles = $this->candles(['100', '101', '102', '103', '104', '105', '106', '107']);
        $provider = new RecordingMarketDataProvider($candles);
        $strategies = ['Idle' => new IdleStrategy];

        $pipeline = $this->pipeline(minimumTrades: 0, minimumWinRate: '0', maximumDrawdown: '100', minimumProfitLoss: '-1000000', trainPercentage: 50);
        $runner = new MarketDataStrategyPipelineRunner($provider, $pipeline);

        $directResult = $pipeline->run($strategies, $candles, '1000');
        $runnerResult = $runner->run('BTCUSDT', Timeframe::Hour1, CarbonImmutable::now(), CarbonImmutable::now(), $strategies, '1000');

        $this->assertEquals($directResult, $runnerResult);
    }

    public function test_it_propagates_exceptions_thrown_by_the_provider(): void
    {
        $provider = new ThrowingMarketDataProvider;
        $runner = $this->runner($provider, minimumTrades: 0, minimumWinRate: '0', maximumDrawdown: '100', minimumProfitLoss: '-1000000', trainPercentage: 50);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('market data unavailable');

        $runner->run('BTCUSDT', Timeframe::Hour1, CarbonImmutable::now(), CarbonImmutable::now(), [], '1000');
    }

    private function runner(
        MarketDataProvider $provider,
        int $minimumTrades,
        string $minimumWinRate,
        string $maximumDrawdown,
        string $minimumProfitLoss,
        int $trainPercentage,
    ): MarketDataStrategyPipelineRunner {
        return new MarketDataStrategyPipelineRunner(
            $provider,
            $this->pipeline($minimumTrades, $minimumWinRate, $maximumDrawdown, $minimumProfitLoss, $trainPercentage),
        );
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

final class RecordingMarketDataProvider implements MarketDataProvider
{
    public ?string $requestedSymbol = null;

    public ?Timeframe $requestedTimeframe = null;

    public ?CarbonImmutable $requestedFrom = null;

    public ?CarbonImmutable $requestedTo = null;

    /**
     * @param  Candle[]  $candles
     */
    public function __construct(private readonly array $candles) {}

    public function getHistoricalCandles(string $symbol, Timeframe $timeframe, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $this->requestedSymbol = $symbol;
        $this->requestedTimeframe = $timeframe;
        $this->requestedFrom = $from;
        $this->requestedTo = $to;

        return $this->candles;
    }
}

final class ThrowingMarketDataProvider implements MarketDataProvider
{
    public function getHistoricalCandles(string $symbol, Timeframe $timeframe, CarbonImmutable $from, CarbonImmutable $to): array
    {
        throw new RuntimeException('market data unavailable');
    }
}

/**
 * Records, per evaluation batch (TRAIN then VALIDATION), the largest
 * candle slice it was given — which is the full window for that batch. A
 * batch boundary is detected by the slice length resetting to 1, which is
 * how {@see StrategyEvaluator} starts counting again for each `evaluate()`
 * call.
 */
final class CandleCapturingStrategy implements Strategy
{
    /** @var list<Candle[]> */
    public array $windows = [];

    public function generate(array $candles): Signal
    {
        if (count($candles) === 1) {
            $this->windows[] = $candles;
        } else {
            $this->windows[array_key_last($this->windows)] = $candles;
        }

        return new Signal(SignalType::HOLD, 'spy', CarbonImmutable::now());
    }
}

/**
 * Test double that never trades, always returning HOLD.
 */
final class IdleStrategy implements Strategy
{
    public function generate(array $candles): Signal
    {
        return new Signal(
            type: SignalType::HOLD,
            reason: 'idle test signal',
            generatedAt: CarbonImmutable::now(),
        );
    }
}
