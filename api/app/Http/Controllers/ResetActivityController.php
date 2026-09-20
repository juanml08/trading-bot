<?php

namespace App\Http\Controllers;

use App\Actions\ResetActivityAction;
use App\Models\BotEvent;
use App\Models\TradingAccount;
use Illuminate\Http\JsonResponse;

/**
 * HTTP entry point for "Resetear actividad" — clears the Activity log
 * ({@see BotEvent}) for development/testing without touching
 * any other bot data.
 */
class ResetActivityController extends Controller
{
    public function __invoke(ResetActivityAction $action): JsonResponse
    {
        $action(TradingAccount::current());

        return response()->json(['message' => 'Actividad eliminada.']);
    }
}
