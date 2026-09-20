<?php

namespace App\Http\Controllers;

use App\Models\ActiveStrategy;
use App\Models\Trade;
use App\Models\TradingAccount;
use Illuminate\Http\JsonResponse;

/**
 * HTTP entry point for reading the account's current control-center state:
 * the active strategy, its accumulated cycle P/L, when it will next be
 * evaluated, and any open position — so the frontend can restore all of it
 * after a page reload.
 */
class AutomaticModeStatusController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $account = TradingAccount::current();

        $active = $account->activeStrategies()
            ->with('strategy')
            ->latest('id')
            ->first();

        $openTrade = $account->trades()
            ->with('asset')
            ->where('status', 'open')
            ->latest('opened_at')
            ->first();

        return response()->json([
            'activeStrategy' => $active,
            'cycleProfitLoss' => $active === null ? null : $this->cycleProfitLoss($active),
            'nextReview' => $active === null ? null : [
                'lastEvaluatedAt' => $active->last_evaluated_at,
                'nextDueAt' => $active->nextEvaluationAt(),
            ],
            'openPosition' => $openTrade === null ? null : [
                'symbol' => $openTrade->asset->symbol,
                'entryPrice' => $openTrade->entry_price,
                'capitalUsed' => $openTrade->capital_used,
                'openedAt' => $openTrade->opened_at,
            ],
        ]);
    }

    /**
     * Sums only closed trades' profit_loss for this active-strategy
     * assignment, using bcmath for the same precision the rest of the
     * trading pipeline uses. Starts at "0" for a freshly applied strategy,
     * since it has no trades yet.
     */
    private function cycleProfitLoss(ActiveStrategy $active): string
    {
        return $active->trades()
            ->where('status', 'closed')
            ->get()
            ->reduce(
                fn (string $carry, Trade $trade): string => bcadd($carry, (string) $trade->profit_loss, 8),
                '0',
            );
    }
}
