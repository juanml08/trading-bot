<?php

namespace App\Http\Controllers;

use App\Actions\Strategy\StartAutomaticModeAction;
use App\Models\TradingAccount;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * HTTP entry point for "Iniciar automático".
 */
class StartAutomaticModeController extends Controller
{
    public function __invoke(StartAutomaticModeAction $action): JsonResponse
    {
        try {
            $active = $action(TradingAccount::current());
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($active->load('strategy'));
    }
}
