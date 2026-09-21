<?php

namespace App\Http\Controllers;

use App\Actions\Strategy\StopTradingCycleAction;
use App\Models\TradingAccount;
use App\Trading\ActiveTradingCyclePresenter;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * HTTP entry point for Fase 4A #3: stops one specific cycle, identified by
 * id and scoped to the current account (see {@see StopTradingCycleAction}).
 */
class StopTradingCycleController extends Controller
{
    public function __invoke(int $cycle, StopTradingCycleAction $action): JsonResponse
    {
        $cycleModel = TradingAccount::current()->activeTradingCycles()->with(['asset', 'strategy', 'activeStrategy'])->find($cycle);

        if ($cycleModel === null) {
            return response()->json(['message' => 'Ciclo no encontrado.'], 404);
        }

        try {
            $stopped = $action($cycleModel);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(ActiveTradingCyclePresenter::summarize($stopped->load(['asset', 'strategy', 'activeStrategy', 'botEvents'])));
    }
}
