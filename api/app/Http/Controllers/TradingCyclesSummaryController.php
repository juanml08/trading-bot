<?php

namespace App\Http\Controllers;

use App\Models\ActiveTradingCycle;
use App\Models\Trade;
use App\Models\TradingAccount;
use App\Trading\ActiveTradingCycleState;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * HTTP entry point for Fase 4A #5: the Dashboard's summary cards. Every
 * figure is computed here from {@see ActiveTradingCycle} and
 * {@see Trade} — the frontend only renders these, it never recalculates
 * them (same rule as {@see ActiveTradingCyclePresenter}).
 */
class TradingCyclesSummaryController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $account = TradingAccount::current();
        $startOfToday = CarbonImmutable::now()->startOfDay();

        $activeCycles = $account->activeTradingCycles()->active()->count();
        $openPositions = $account->activeTradingCycles()->where('state', ActiveTradingCycleState::PositionOpen)->count();
        $expiredToday = $account->activeTradingCycles()
            ->where('state', ActiveTradingCycleState::Expired)
            ->where('updated_at', '>=', $startOfToday)
            ->count();

        $closedTradesToday = $account->trades()
            ->where('status', 'closed')
            ->where('closed_at', '>=', $startOfToday)
            ->get();

        $maxActive = (int) config('trading.active_cycles.max_active');

        return response()->json([
            'active_cycles' => $activeCycles,
            'free_slots' => max(0, $maxActive - $activeCycles),
            'open_positions' => $openPositions,
            'expired_today' => $expiredToday,
            'closed_trades_today' => $closedTradesToday->count(),
            'today_profit_loss' => $this->sumProfitLoss($closedTradesToday),
        ]);
    }

    /**
     * @param  Collection<int, Trade>  $trades
     */
    private function sumProfitLoss($trades): string
    {
        return $trades->reduce(
            fn (string $carry, Trade $trade): string => bcadd($carry, (string) $trade->profit_loss, 8),
            '0',
        );
    }
}
