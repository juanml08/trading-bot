<?php

namespace App\Http\Controllers;

use App\Models\ActiveStrategy;
use App\Models\BotEvent;
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

        $automaticSearch = $account->automaticSearchState;

        return response()->json([
            'activeStrategy' => $active,
            'cycleProfitLoss' => $active === null ? null : $this->cycleProfitLoss($active),
            'nextReview' => $active === null ? null : [
                'lastEvaluatedAt' => $active->last_evaluated_at,
                'nextDueAt' => $active->nextEvaluationAt(),
            ],
            'openPosition' => $openTrade === null ? null : $this->openPosition($openTrade),
            'automaticSearch' => $automaticSearch === null ? null : [
                'status' => $automaticSearch->status,
                'symbol' => $automaticSearch->symbol,
                'timeframe' => $automaticSearch->timeframe,
                'lastSearchedAt' => $automaticSearch->last_searched_at,
                'nextSearchAt' => $automaticSearch->next_search_at,
                'lastCycle' => $automaticSearch->last_cycle,
            ],
        ]);
    }

    /**
     * The open position plus what the UI needs to value it, all read-only
     * (no financial logic changes):
     *
     * - `currentPrice`/`currentPriceAt`: the close price of the last candle the
     *   bot evaluated for this asset (its `candle_processed` event), NOT a live
     *   ticker — so `unrealizedProfitLoss` is approximate, as of that moment.
     * - `maxHoldingExpiresAt`: `opened_at` + `trading.risk_exit.max_holding_hours`
     *   (null when the rule is disabled). The position is closed by the first
     *   evaluation at or after that moment.
     *
     * @return array<string, mixed>
     */
    private function openPosition(Trade $trade): array
    {
        $lastEvaluation = BotEvent::query()
            ->where('account_id', $trade->account_id)
            ->where('asset', $trade->asset->symbol)
            ->where('event_type', 'candle_processed')
            ->where('created_at', '>=', $trade->opened_at)
            ->latest('id')
            ->first();

        $currentPrice = $lastEvaluation?->data['price'] ?? null;
        $maxHoldingHours = (int) config('trading.risk_exit.max_holding_hours');

        return [
            'symbol' => $trade->asset->symbol,
            'entryPrice' => $trade->entry_price,
            'capitalUsed' => $trade->capital_used,
            'openedAt' => $trade->opened_at,
            'quantity' => $trade->quantity,
            'currentPrice' => $currentPrice,
            'currentPriceAt' => $lastEvaluation?->created_at,
            'unrealizedProfitLoss' => $currentPrice === null
                ? null
                : bcsub(bcmul((string) $trade->quantity, (string) $currentPrice, 8), (string) $trade->capital_used, 8),
            'maxHoldingHours' => $maxHoldingHours,
            'maxHoldingExpiresAt' => $maxHoldingHours > 0 ? $trade->opened_at->copy()->addHours($maxHoldingHours) : null,
        ];
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
