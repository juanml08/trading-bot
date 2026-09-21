<?php

namespace App\Http\Controllers;

use App\Models\ActiveTradingCycle;
use App\Models\TradingAccount;
use App\Trading\ActiveTradingCyclePresenter;
use Illuminate\Http\JsonResponse;

/**
 * HTTP entry point for Fase 4A #1: the multi-cycle Dashboard's cycle table.
 * Returns every {@see ActiveTradingCycle} for the account
 * (HOLD/POSITION_OPEN/CLOSED/EXPIRED alike — the Dashboard mock explicitly
 * shows a CLOSED row alongside live ones for context), most recent first,
 * each already shaped by {@see ActiveTradingCyclePresenter} so the frontend
 * never computes state or time itself.
 */
class ActiveTradingCyclesController extends Controller
{
    /**
     * Caps how many cycles a single request returns — plenty for the
     * Dashboard's "up to 5 live cycles plus recent history" use case, and a
     * guard against an unbounded scan once an account has been running for
     * months (mirrors {@see AutomaticEventsController}'s own limit).
     */
    private const int LIMIT = 50;

    public function __invoke(): JsonResponse
    {
        // `botEvents` is loaded in full (not constrained to the latest one):
        // a per-relation `limit()` constraint in `with()` caps the whole
        // eager-loaded result set, not each cycle's own latest event, which
        // would silently starve every cycle but the first of its history.
        // The relation is already ordered latest-first (see
        // ActiveTradingCycle::botEvents()), so this stays correct and simple.
        $cycles = TradingAccount::current()
            ->activeTradingCycles()
            ->with(['asset', 'strategy', 'activeStrategy', 'botEvents'])
            ->latest('id')
            ->limit(self::LIMIT)
            ->get();

        return response()->json([
            'cycles' => $cycles->map(fn ($cycle) => ActiveTradingCyclePresenter::summarize($cycle))->values(),
        ]);
    }
}
