<?php

namespace Tests\Unit;

use App\Actions\Strategy\SearchStrategiesAction;
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
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SearchStrategiesActionTest extends TestCase
{
    public function test_it_runs_the_pipeline_with_the_given_symbol_timeframe_and_dates(): void
    {
        $provider = new SearchStrategiesRecordingMarketDataProvider([]);
        $action = $this->action($provider);

        $symbol = 'ETHUSDT';
        $timeframe = Timeframe::Hour4;
        $from = CarbonImmutable::parse('2024-01-01 00:00:00');
        $to = CarbonImmutable::parse('2024-02-01 00:00:00');

        $action($symbol, $timeframe, $from, $to, ['Idle' => new SearchStrategiesIdleStrategy], '1000');

        $this->assertSame($symbol, $provider->requestedSymbol);
        $this->assertSame($timeframe, $provider->requestedTimeframe);
        $this->assertSame($from, $provider->requestedFrom);
        $this->assertSame($to, $provider->requestedTo);
    }

    public function test_it_forwards_the_initial_capital_unmodified(): void
    {
        $candles = $this->candles(['100', '101', '102', '103']);
        $provider = new SearchStrategiesRecordingMarketDataProvider($candles);
        $action = $this->action($provider);

        $result = $action('BTCUSDT', Timeframe::Hour1, CarbonImmutable::now(), CarbonImmutable::now()->addHour(), ['Idle' => new SearchStrategiesIdleStrategy], '20');

        $this->assertCount(1, $result->validationResults);
        $this->assertSame('20', $result->validationResults[0]->candidate->evaluation->initialCapital);
        $this->assertSame('20', $result->validationResults[0]->validationEvaluation->initialCapital);
    }

    public function test_it_keeps_decimal_capital_as_a_string_without_converting_to_float(): void
    {
        $candles = $this->candles(['100', '101', '102', '103']);
        $provider = new SearchStrategiesRecordingMarketDataProvider($candles);
        $action = $this->action($provider);

        $result = $action('BTCUSDT', Timeframe::Hour1, CarbonImmutable::now(), CarbonImmutable::now()->addHour(), ['Idle' => new SearchStrategiesIdleStrategy], '20.50');

        $this->assertIsString($result->validationResults[0]->candidate->evaluation->initialCapital);
        $this->assertSame('20.50', $result->validationResults[0]->candidate->evaluation->initialCapital);
    }

    public function test_it_forwards_strategies_unmodified(): void
    {
        $candles = $this->candles(['100', '101', '102', '103']);
        $provider = new SearchStrategiesRecordingMarketDataProvider($candles);
        $action = $this->action($provider);

        $result = $action('BTCUSDT', Timeframe::Hour1, CarbonImmutable::now(), CarbonImmutable::now()->addHour(), ['Idle' => new SearchStrategiesIdleStrategy], '1000');

        $this->assertCount(1, $result->validationResults);
        $this->assertSame('Idle', $result->validationResults[0]->candidate->strategyName);
    }

    public function test_it_returns_exactly_what_the_runner_produces_for_the_same_inputs(): void
    {
        $candles = $this->candles(['100', '101', '102', '103']);
        $strategies = ['Idle' => new SearchStrategiesIdleStrategy];

        $providerForAction = new SearchStrategiesRecordingMarketDataProvider($candles);
        $providerForRunner = new SearchStrategiesRecordingMarketDataProvider($candles);

        $action = $this->action($providerForAction);
        $runner = new MarketDataStrategyPipelineRunner($providerForRunner, $this->pipeline());

        $symbol = 'BTCUSDT';
        $timeframe = Timeframe::Hour1;
        $from = CarbonImmutable::now();
        $to = CarbonImmutable::now()->addHour();

        $actionResult = $action($symbol, $timeframe, $from, $to, $strategies, '1000');
        $runnerResult = $runner->run($symbol, $timeframe, $from, $to, $strategies, '1000');

        $this->assertEquals($runnerResult, $actionResult);
    }

    public function test_it_rejects_zero_initial_capital(): void
    {
        $action = $this->action(new SearchStrategiesRecordingMarketDataProvider([]));

        $this->expectException(InvalidArgumentException::class);

        $action('BTCUSDT', Timeframe::Hour1, CarbonImmutable::now(), CarbonImmutable::now()->addHour(), ['Idle' => new SearchStrategiesIdleStrategy], '0');
    }

    public function test_it_rejects_negative_initial_capital(): void
    {
        $action = $this->action(new SearchStrategiesRecordingMarketDataProvider([]));

        $this->expectException(InvalidArgumentException::class);

        $action('BTCUSDT', Timeframe::Hour1, CarbonImmutable::now(), CarbonImmutable::now()->addHour(), ['Idle' => new SearchStrategiesIdleStrategy], '-20');
    }

    public function test_it_rejects_a_from_date_that_is_not_before_to(): void
    {
        $action = $this->action(new SearchStrategiesRecordingMarketDataProvider([]));

        $now = CarbonImmutable::now();

        $this->expectException(InvalidArgumentException::class);

        $action('BTCUSDT', Timeframe::Hour1, $now, $now, ['Idle' => new SearchStrategiesIdleStrategy], '1000');
    }

    public function test_it_rejects_an_empty_symbol(): void
    {
        $action = $this->action(new SearchStrategiesRecordingMarketDataProvider([]));

        $this->expectException(InvalidArgumentException::class);

        $action('', Timeframe::Hour1, CarbonImmutable::now(), CarbonImmutable::now()->addHour(), ['Idle' => new SearchStrategiesIdleStrategy], '1000');
    }

    public function test_it_rejects_an_empty_list_of_strategies(): void
    {
        $action = $this->action(new SearchStrategiesRecordingMarketDataProvider([]));

        $this->expectException(InvalidArgumentException::class);

        $action('BTCUSDT', Timeframe::Hour1, CarbonImmutable::now(), CarbonImmutable::now()->addHour(), [], '1000');
    }

    public function test_it_propagates_exceptions_thrown_by_the_provider(): void
    {
        $action = $this->action(new SearchStrategiesThrowingMarketDataProvider);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('market data unavailable');

        $action('BTCUSDT', Timeframe::Hour1, CarbonImmutable::now(), CarbonImmutable::now()->addHour(), ['Idle' => new SearchStrategiesIdleStrategy], '1000');
    }

    private function action(MarketDataProvider $provider): SearchStrategiesAction
    {
        $runner = new MarketDataStrategyPipelineRunner($provider, $this->pipeline());

        return new SearchStrategiesAction($runner);
    }

    private function pipeline(): StrategyPipeline
    {
        return new StrategyPipeline(
            evaluator: new StrategyEvaluator,
            selector: new StrategySelector,
            split: new TrainValidationSplit(50),
            minimumTrades: 0,
            minimumWinRate: '0',
            maximumDrawdown: '100',
            minimumProfitLoss: '-1000000',
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

final class SearchStrategiesRecordingMarketDataProvider implements MarketDataProvider
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

final class SearchStrategiesThrowingMarketDataProvider implements MarketDataProvider
{
    public function getHistoricalCandles(string $symbol, Timeframe $timeframe, CarbonImmutable $from, CarbonImmutable $to): array
    {
        throw new RuntimeException('market data unavailable');
    }
}

/**
 * Test double that never trades, always returning HOLD.
 */
final class SearchStrategiesIdleStrategy implements Strategy
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
