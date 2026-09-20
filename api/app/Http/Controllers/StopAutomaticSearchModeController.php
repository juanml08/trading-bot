<?php

namespace App\Http\Controllers;

use App\Actions\Strategy\StopAutomaticModeAction;
use App\Actions\Strategy\StopAutomaticSearchModeAction;
use App\Models\TradingAccount;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * HTTP entry point for "Detener" in Modo Automático.
 */
class StopAutomaticSearchModeController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $action = new StopAutomaticSearchModeAction(new StopAutomaticModeAction);

        try {
            $state = $action(TradingAccount::current());
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($state);
    }
}
