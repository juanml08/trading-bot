<?php

namespace App\Http\Controllers;

use App\Models\Trade;
use App\Models\TradingAccount;
use Illuminate\Http\JsonResponse;

/**
 * HTTP entry point for the control-center trade history: the account's most
 * recent {@see Trade} rows, most recent first.
 */
class AutomaticTradesController extends Controller
{
    private const int LIMIT = 20;

    public function __invoke(): JsonResponse
    {
        $trades = TradingAccount::current()
            ->trades()
            ->with('asset')
            ->latest('id')
            ->limit(self::LIMIT)
            ->get();

        return response()->json(['trades' => $trades]);
    }
}
