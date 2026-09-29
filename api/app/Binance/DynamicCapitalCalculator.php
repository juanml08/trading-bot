<?php

namespace App\Binance;

use App\Automation\AutomaticTradingCycle;
use App\Models\ActiveTradingCycle;

/**
 * Computes the USDT capital a single BUY may use, from the account's real
 * available balance and the fixed number of
 * {@see ActiveTradingCycle} slots
 * (`config('trading.active_cycles.max_active')`), instead of a fixed
 * per-trade amount:
 *
 *     capital por slot = balance disponible x (1 - reserve_percent) / MAX_ACTIVE_CYCLES
 *
 * Called fresh on every BUY (see {@see AutomaticTradingCycle})
 * so it always reflects the balance at the moment a position is actually
 * opened, not a stale value from when the slot's candidate was activated.
 * `max_active` slots bound *capacity*, not an obligation to fill every one —
 * a slot left empty simply leaves its share of the balance unused. Has no
 * knowledge of Strategy, Risk, or Execution.
 */
final class DynamicCapitalCalculator
{
    public function __construct(
        private readonly BinanceAccountClient $client,
    ) {}

    /**
     * "0" when there are no configured slots to divide the balance across —
     * callers must treat that as "nothing to trade with", not attempt a
     * division by zero.
     */
    public function capitalPerSlot(): string
    {
        $maxSlots = (int) config('trading.active_cycles.max_active');

        if ($maxSlots <= 0) {
            return '0';
        }

        $reservePercent = (string) config('trading.capital.reserve_percent');
        $usableFraction = bcsub('1', bcdiv($reservePercent, '100', 18), 18);
        $available = $this->client->availableBalance('USDT');
        $usableBalance = bcmul($available, $usableFraction, 18);

        return bcdiv($usableBalance, (string) $maxSlots, 18);
    }
}
