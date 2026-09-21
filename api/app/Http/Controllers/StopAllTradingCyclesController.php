<?php

namespace App\Http\Controllers;

use App\Actions\Strategy\StopAllTradingCyclesAction;
use App\Models\TradingAccount;
use Illuminate\Http\JsonResponse;

/**
 * HTTP entry point for Fase 4A #4: stops every active cycle for the current
 * account (see {@see StopAllTradingCyclesAction}).
 */
class StopAllTradingCyclesController extends Controller
{
    public function __invoke(StopAllTradingCyclesAction $action): JsonResponse
    {
        $stopped = $action(TradingAccount::current());

        return response()->json(['stopped' => $stopped]);
    }
}
