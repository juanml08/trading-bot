<?php

namespace App\Http\Controllers;

use App\Actions\Strategy\ActivateStrategyAction;
use App\Actions\Strategy\ActivateTradingCycleAction;
use App\Actions\Strategy\RunAutomaticSearchAction;
use App\Actions\Strategy\SearchStrategiesAction;
use App\Actions\Strategy\StartAutomaticModeAction;
use App\Actions\Strategy\StartAutomaticSearchModeAction;
use App\Http\Requests\StartAutomaticSearchRequest;
use App\MarketData\BinanceMarketDataProvider;
use App\MarketData\BinanceSymbolUniverseProvider;
use App\Models\TradingAccount;
use App\Opportunity\OpportunityScanner;
use App\Orchestration\MarketDataStrategyPipelineRunner;
use App\Strategy\StrategyEvaluator;
use App\Strategy\StrategyPipeline;
use App\Strategy\StrategySelector;
use App\Strategy\TrainValidationSplit;
use Illuminate\Http\JsonResponse;

/**
 * HTTP entry point for "Iniciar automático" (Modo Automático): starts the
 * autonomous strategy-selection loop and runs its first search attempt
 * synchronously, so the response already reflects whether a candidate was
 * found and applied.
 */
class StartAutomaticSearchModeController extends Controller
{
    public function __invoke(StartAutomaticSearchRequest $request): JsonResponse
    {
        $action = $this->buildAction();

        $state = $action(
            TradingAccount::current(),
            $request->timeframe(),
            $request->capital(),
            $request->mode(),
        );

        return response()->json($state);
    }

    private function buildAction(): StartAutomaticSearchModeAction
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

        $runAction = new RunAutomaticSearchAction($searchAction, new ActivateTradingCycleAction(new ActivateStrategyAction), new StartAutomaticModeAction, $scanner);

        return new StartAutomaticSearchModeAction($runAction);
    }
}
