<?php

namespace App\Http\Controllers;

use App\Actions\Strategy\SearchStrategiesAction;
use App\Http\Requests\SearchStrategiesRequest;
use App\MarketData\BinanceMarketDataProvider;
use App\Orchestration\MarketDataStrategyPipelineRunner;
use App\Strategy\StrategyCatalog;
use App\Strategy\StrategyEvaluator;
use App\Strategy\StrategyPipeline;
use App\Strategy\StrategyPipelineResult;
use App\Strategy\StrategySelector;
use App\Strategy\TrainValidationSplit;
use Illuminate\Http\JsonResponse;

/**
 * HTTP entry point for the existing "Buscar estrategia" use case. This is a
 * thin HTTP layer: it converts the validated request into the types
 * {@see SearchStrategiesAction} needs, invokes it, and returns
 * {@see StrategyPipelineResult} as JSON. It contains no business logic of
 * its own — that remains the Action's and the pipeline's responsibility.
 */
class StrategySearchController extends Controller
{
    public function __invoke(SearchStrategiesRequest $request): JsonResponse
    {
        $action = $this->buildAction();

        $result = $action(
            $request->symbol(),
            $request->timeframe(),
            $request->from(),
            $request->to(),
            StrategyCatalog::all(),
            $request->capital(),
        );

        return response()->json($result);
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
            minimumTrades: config('trading.discovery.minimum_trades'),
            minimumWinRate: config('trading.discovery.minimum_win_rate'),
            maximumDrawdown: config('trading.discovery.maximum_drawdown'),
            minimumProfitLoss: config('trading.discovery.minimum_profit_loss'),
        );

        $runner = new MarketDataStrategyPipelineRunner($marketDataProvider, $pipeline);

        return new SearchStrategiesAction($runner);
    }
}
