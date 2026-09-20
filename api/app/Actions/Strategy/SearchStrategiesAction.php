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
 * `$initialCapital` is evaluation capital: the virtual amount a backtest run
 * is simulated with. It is deliberately distinct from two other, unrelated
 * amounts this action knows nothing about: a `TradingAccount`'s real
 * balance, and whatever "authorized capital" ceiling a future Risk Manager
 * may enforce against that balance (`RiskSetting` and `TradingAccount`
 * exist as models but have no migration yet, so there is nothing real to
 * read from them today). This action never reads or compares against
 * either; it forwards `$initialCapital` unmodified, exactly as received,
 * all the way to the pipeline. Enforcing "evaluation capital must not
 * exceed authorized capital" belongs to that future Risk Manager stage,
 * not here.
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
