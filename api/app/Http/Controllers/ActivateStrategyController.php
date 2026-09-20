<?php

namespace App\Http\Controllers;

use App\Actions\Strategy\ActivateStrategyAction;
use App\Http\Requests\ActivateStrategyRequest;
use App\Models\ActiveStrategy;
use App\Models\TradingAccount;
use Illuminate\Http\JsonResponse;

/**
 * HTTP entry point for "Aplicar". Thin HTTP layer: converts the validated
 * request into what {@see ActivateStrategyAction} needs and returns the
 * resulting {@see ActiveStrategy} as JSON.
 */
class ActivateStrategyController extends Controller
{
    public function __invoke(ActivateStrategyRequest $request, ActivateStrategyAction $action): JsonResponse
    {
        $active = $action(
            TradingAccount::current(),
            $request->strategyName(),
            $request->symbol(),
            $request->timeframe(),
            $request->capital(),
            $request->mode(),
        );

        return response()->json($active->load('strategy'));
    }
}
