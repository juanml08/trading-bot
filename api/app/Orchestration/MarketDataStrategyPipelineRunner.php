<?php

namespace App\Orchestration;

use App\MarketData\MarketDataProvider;
use App\MarketData\Timeframe;
use App\Strategy\Strategy;
use App\Strategy\StrategyPipeline;
use App\Strategy\StrategyPipelineResult;
use App\Strategy\TrainValidationSplit;
use Carbon\CarbonImmutable;

/**
 * Connects {@see MarketDataProvider} to {@see StrategyPipeline}: fetches the
 * historical candles for a symbol/timeframe/period and hands them, together
 * with the given strategies and capital, to the pipeline as-is.
 *
 * This is wiring only. It does not generate signals, backtest, run
 * Discovery/Validation/Selection, size positions, manage risk, or execute
 * orders — all of that already lives in StrategyPipeline and what it calls.
 * It never sorts or otherwise modifies the candles it receives: providers
 * are contractually required to return them oldest-first (see
 * {@see MarketDataProvider::getHistoricalCandles()}), and this class trusts
 * that precondition, mirroring how {@see TrainValidationSplit}
 * already trusts it.
 */
final readonly class MarketDataStrategyPipelineRunner
{
    public function __construct(
        private MarketDataProvider $marketDataProvider,
        private StrategyPipeline $pipeline,
    ) {}

    /**
     * @param  array<string, Strategy>  $strategies  keyed by strategy name
     */
    public function run(
        string $symbol,
        Timeframe $timeframe,
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $strategies,
        string $initialCapital,
    ): StrategyPipelineResult {
        $candles = $this->marketDataProvider->getHistoricalCandles($symbol, $timeframe, $from, $to);

        return $this->pipeline->run($strategies, $candles, $initialCapital);
    }
}
