<?php

namespace App\Http\Controllers;

use App\Actions\Strategy\StopAutomaticModeAction;
use App\Models\TradingAccount;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * HTTP entry point for "Detener".
 */
class StopAutomaticModeController extends Controller
{
    public function __invoke(StopAutomaticModeAction $action): JsonResponse
    {
        try {
            $active = $action(TradingAccount::current());
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($active->load('strategy'));
    }
}
