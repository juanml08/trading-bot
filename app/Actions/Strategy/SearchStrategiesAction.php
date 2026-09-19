<?php

namespace App\Actions\Strategy;

use App\MarketData\Timeframe;
use App\Orchestration\MarketDataStrategyPipelineRunner;
use App\Strategy\Strategy;
use App\Strategy\StrategyPipeline;
use App\Strategy\StrategyPipelineResult;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Application-level use case for "Buscar estrategia": validates the inputs
 * a caller must supply and delegates straight to
 * {@see MarketDataStrategyPipelineRunner}, which already wires Market Data
 * to {@see StrategyPipeline}.
 *
 * `$initialCapital` is the capital the user explicitly authorizes for this
 * evaluation — a distinct concept from any `TradingAccount` balance. This
 * action never reads or compares against account balances; it forwards
 * `$initialCapital` unmodified, exactly as received, all the way to the
 * pipeline.
 *
 * This is orchestration only: it does not fetch market data, run
 * backtesting, Discovery, Validation, or Selection itself — all of that
 * remains the runner's and the pipeline's responsibility.
 */
final readonly class SearchStrategiesAction
{
    public function __construct(
        private MarketDataStrategyPipelineRunner $runner,
    ) {}

    /**
     * @param  array<string, Strategy>  $strategies  keyed by strategy name
     */
    public function __invoke(
        string $symbol,
        Timeframe $timeframe,
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $strategies,
        string $initialCapital,
    ): StrategyPipelineResult {
        $this->validate($symbol, $from, $to, $strategies, $initialCapital);

        return $this->runner->run($symbol, $timeframe, $from, $to, $strategies, $initialCapital);
    }

    /**
     * @param  array<string, Strategy>  $strategies
     */
    private function validate(
        string $symbol,
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $strategies,
        string $initialCapital,
    ): void {
        if (trim($symbol) === '') {
            throw new InvalidArgumentException('Symbol must not be empty.');
        }

        if (! $from->lt($to)) {
            throw new InvalidArgumentException('"from" must be before "to".');
        }

        if ($strategies === []) {
            throw new InvalidArgumentException('At least one strategy must be provided.');
        }

        if (bccomp($initialCapital, '0', 18) <= 0) {
            throw new InvalidArgumentException('Initial capital must be greater than zero.');
        }
    }
}
