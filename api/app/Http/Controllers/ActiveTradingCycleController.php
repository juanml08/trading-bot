<?php

namespace App\Http\Controllers;

use App\Models\TradingAccount;
use App\Trading\ActiveTradingCyclePresenter;
use Illuminate\Http\JsonResponse;

/**
 * HTTP entry point for Fase 4A #2: a single cycle's detail, including its
 * full chronological history (see {@see ActiveTradingCyclePresenter}).
 * Scoped to the current account's own cycles — a nonexistent (or another
 * account's) cycle id returns 404 rather than leaking one.
 */
class ActiveTradingCycleController extends Controller
{
    public function __invoke(int $cycle): JsonResponse
    {
        $cycleModel = TradingAccount::current()
            ->activeTradingCycles()
            ->with(['asset', 'strategy', 'activeStrategy', 'botEvents'])
            ->find($cycle);

        if ($cycleModel === null) {
            return response()->json(['message' => 'Ciclo no encontrado.'], 404);
        }

        return response()->json(ActiveTradingCyclePresenter::detail($cycleModel));
    }
}
