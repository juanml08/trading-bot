<?php

namespace App\Http\Controllers;

use App\Models\BotEvent;
use App\Models\TradingAccount;
use Illuminate\Http\JsonResponse;

/**
 * HTTP entry point for the control-center activity console: the account's
 * most recent {@see BotEvent} entries, oldest first (as a
 * console log reads), so the frontend does not need to reverse them.
 */
class AutomaticEventsController extends Controller
{
    private const int LIMIT = 20;

    public function __invoke(): JsonResponse
    {
        $events = TradingAccount::current()
            ->botEvents()
            ->latest('id')
            ->limit(self::LIMIT)
            ->get()
            ->reverse()
            ->values();

        return response()->json(['events' => $events]);
    }
}
