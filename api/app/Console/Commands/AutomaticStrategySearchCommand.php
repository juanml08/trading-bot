<?php

namespace App\Console\Commands;

use App\Actions\Strategy\ActivateStrategyAction;
use App\Actions\Strategy\ActivateTradingCycleAction;
use App\Actions\Strategy\RunAutomaticSearchAction;
use App\Actions\Strategy\SearchStrategiesAction;
use App\Actions\Strategy\StartAutomaticModeAction;
use App\MarketData\BinanceMarketDataProvider;
use App\MarketData\BinanceSymbolUniverseProvider;
use App\Models\ActiveTradingCycle;
use App\Models\AutomaticSearchState;
use App\Models\BotEvent;
use App\Opportunity\OpportunityScanner;
use App\Orchestration\MarketDataStrategyPipelineRunner;
use App\Strategy\StrategyEvaluator;
use App\Strategy\StrategyPipeline;
use App\Strategy\StrategySelector;
use App\Strategy\TrainValidationSplit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Scheduled entry point for Modo Automático's strategy search retry (see
 * routes/console.php, registered with `Schedule::command(...)->everyMinute()`).
 *
 * For every {@see AutomaticSearchState} with `status = running`, only
 * attempts a search once it is due — either no attempt has run yet, or the
 * configured retry interval has elapsed since the last one — and
 * {@see RunAutomaticSearchAction} itself skips the attempt entirely if the
 * account has no free {@see ActiveTradingCycle} slot (see
 * `config('trading.active_cycles.max_active')`). This is what keeps the
 * search from re-running on every tick, and from ever running at all while
 * the account is already at its active-cycle limit.
 *
 * A failure attempting one account's search is logged as a {@see BotEvent}
 * and does not stop the others from being processed.
 */
#[Signature('automatic:search')]
#[Description('Retry the automatic strategy search for every account with Modo Automático running, if due')]
class AutomaticStrategySearchCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Only this command: a cycle over 30 assets x 400 strategies builds a
        // ~50 MB diagnostic array that is then JSON-encoded into `last_cycle`
        // (~8 MB), so the 128 MB CLI default leaves almost no headroom for
        // the market data in flight. Not raised globally on purpose.
        ini_set('memory_limit', '512M');

        $action = $this->buildAction();

        // `last_cycle` is deliberately not selected: it is write-only here
        // (RunAutomaticSearchAction overwrites it, never reads it) and can
        // weigh ~8 MB of JSON, which json-decoded into a PHP array is several
        // times that — enough, with the new cycle held alongside it, to exhaust
        // the 128 MB CLI memory_limit (exit code 255).
        $states = AutomaticSearchState::query()
            ->select(['id', 'account_id', 'symbol', 'timeframe', 'capital', 'mode', 'status', 'last_searched_at', 'next_search_at', 'created_at', 'updated_at'])
            ->where('status', AutomaticSearchState::STATUS_RUNNING)
            ->get();

        foreach ($states as $state) {
            if (! $state->isDue()) {
                continue;
            }

            try {
                $action($state);
            } catch (Throwable $exception) {
                BotEvent::query()->create([
                    'account_id' => $state->account_id,
                    'event_type' => 'error',
                    'asset' => $state->symbol,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }

    private function buildAction(): RunAutomaticSearchAction
    {
        $marketDataProvider = new BinanceMarketDataProvider(
            baseUrl: config('services.binance.base_url'),
        );

        $pipeline = new StrategyPipeline(
            evaluator: new StrategyEvaluator,
            selector: new StrategySelector,
            split: new TrainValidationSplit(50),
            minimumTrades: config('trading.discovery.minimum_trades'),
            minimumWinRate: config('trading.discovery.minimum_win_rate'),
            maximumDrawdown: config('trading.discovery.maximum_drawdown'),
            minimumProfitLoss: config('trading.discovery.minimum_profit_loss'),
            validationMinimumTrades: config('trading.validation.minimum_trades'),
            validationMinimumWinRate: config('trading.validation.minimum_win_rate'),
            validationMaximumDrawdown: config('trading.validation.maximum_drawdown'),
            validationMinimumProfitLoss: config('trading.validation.minimum_profit_loss'),
        );

        $runner = new MarketDataStrategyPipelineRunner($marketDataProvider, $pipeline);
        $searchAction = new SearchStrategiesAction($runner);

        $universeProvider = new BinanceSymbolUniverseProvider(baseUrl: config('services.binance.base_url'));
        $scanner = new OpportunityScanner($universeProvider, $marketDataProvider);

        return new RunAutomaticSearchAction($searchAction, new ActivateTradingCycleAction(new ActivateStrategyAction), new StartAutomaticModeAction, $scanner);
    }
}
