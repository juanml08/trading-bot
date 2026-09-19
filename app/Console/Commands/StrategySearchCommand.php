<?php

namespace App\Console\Commands;

use App\Actions\Strategy\SearchStrategiesAction;
use App\MarketData\BinanceMarketDataProvider;
use App\MarketData\Timeframe;
use App\Orchestration\MarketDataStrategyPipelineRunner;
use App\Strategy\EmaCrossoverStrategy;
use App\Strategy\SimpleMovingAverageStrategy;
use App\Strategy\SmaCrossoverStrategy;
use App\Strategy\Strategy;
use App\Strategy\StrategyEvaluator;
use App\Strategy\StrategyPipeline;
use App\Strategy\StrategyPipelineResult;
use App\Strategy\StrategySelector;
use App\Strategy\TrainValidationSplit;
use App\Strategy\ValidationResult;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Development-only CLI entry point for the existing "Buscar estrategia" use
 * case. This is a thin CLI/presentation layer: it converts option strings to
 * the types {@see SearchStrategiesAction} needs, invokes it, and prints
 * {@see StrategyPipelineResult}. It contains no business logic of its own —
 * symbol/date/capital validation remains the Action's responsibility.
 */
#[Signature('strategy:search {--symbol=} {--timeframe=} {--from=} {--to=} {--capital=}')]
#[Description('Run the strategy search pipeline manually against live market data')]
class StrategySearchCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $symbol = $this->option('symbol');
        $timeframeOption = $this->option('timeframe');
        $fromOption = $this->option('from');
        $toOption = $this->option('to');
        $capital = $this->option('capital');

        if ($symbol === null || $timeframeOption === null || $fromOption === null || $toOption === null || $capital === null) {
            $this->components->error('--symbol, --timeframe, --from, --to, and --capital are all required.');

            return self::FAILURE;
        }

        $timeframe = Timeframe::tryFrom($timeframeOption);

        if ($timeframe === null) {
            $validValues = implode(', ', array_map(fn (Timeframe $case): string => $case->value, Timeframe::cases()));
            $this->components->error("Invalid --timeframe \"{$timeframeOption}\". Valid values: {$validValues}.");

            return self::FAILURE;
        }

        try {
            $from = CarbonImmutable::parse($fromOption);
        } catch (Throwable) {
            $this->components->error("Invalid --from date \"{$fromOption}\".");

            return self::FAILURE;
        }

        try {
            $to = CarbonImmutable::parse($toOption);
        } catch (Throwable) {
            $this->components->error("Invalid --to date \"{$toOption}\".");

            return self::FAILURE;
        }

        $action = $this->buildAction();

        try {
            $result = $action(
                $symbol,
                $timeframe,
                $from,
                $to,
                $this->strategies(),
                $capital,
            );
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } catch (RuntimeException $exception) {
            $this->components->error("Market data request failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->render($symbol, $timeframe, $from, $to, $capital, $result);

        return self::SUCCESS;
    }

    /**
     * @return array<string, Strategy>
     */
    private function strategies(): array
    {
        return [
            'SMA Fast' => new SimpleMovingAverageStrategy,
            'SMA Medium' => new SmaCrossoverStrategy(shortPeriod: 10, longPeriod: 20),
            'EMA Simple' => new EmaCrossoverStrategy,
        ];
    }

    private function buildAction(): SearchStrategiesAction
    {
        $marketDataProvider = new BinanceMarketDataProvider(
            baseUrl: config('services.binance.base_url'),
        );

        $pipeline = new StrategyPipeline(
            evaluator: new StrategyEvaluator,
            selector: new StrategySelector,
            split: new TrainValidationSplit(50),
            minimumTrades: 0,
            minimumWinRate: '0',
            maximumDrawdown: '100',
            minimumProfitLoss: '-1000000',
        );

        $runner = new MarketDataStrategyPipelineRunner($marketDataProvider, $pipeline);

        return new SearchStrategiesAction($runner);
    }

    private function render(
        string $symbol,
        Timeframe $timeframe,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $capital,
        StrategyPipelineResult $result,
    ): void {
        $this->line('Strategy Search');
        $this->line('---------------');
        $this->newLine();
        $this->line("Symbol: {$symbol}");
        $this->line("Timeframe: {$timeframe->value}");
        $this->line("From: {$from->toDateString()}");
        $this->line("To: {$to->toDateString()}");
        $this->line("Initial Capital: {$capital}");
        $this->newLine();

        $this->line('Validation Results');
        $this->line('------------------');
        $this->newLine();

        if ($result->validationResults === []) {
            $this->line('No strategy reached Validation.');
        }

        foreach ($result->validationResults as $validationResult) {
            $this->renderValidationResult($validationResult);
        }

        $this->newLine();
        $this->line('Selected Strategy');
        $this->line('-----------------');
        $this->newLine();

        if ($result->selectedCandidate === null) {
            $this->line('No strategy selected.');
        } else {
            $this->line($result->selectedCandidate->strategyName);
        }
    }

    private function renderValidationResult(ValidationResult $validationResult): void
    {
        $evaluation = $validationResult->validationEvaluation;
        $status = $validationResult->passed ? 'PASSED' : 'FAILED';

        $this->line("{$validationResult->candidate->strategyName}: {$status}");
        $this->line("Trades: {$evaluation->totalTrades}");
        $this->line("Win Rate: {$evaluation->winRate}");
        $this->line("Profit/Loss: {$evaluation->profitLoss}");
        $this->line("Max Drawdown: {$evaluation->maxDrawdownPercentage}");
        $this->line("Profit Factor: {$evaluation->profitFactor}");

        if (! $validationResult->passed) {
            $this->line('Failed Criteria: '.implode(', ', $validationResult->failedCriteria));
        }

        $this->newLine();
    }
}
