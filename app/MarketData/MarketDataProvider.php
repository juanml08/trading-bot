<?php

namespace App\MarketData;

use Carbon\CarbonImmutable;

/**
 * Contract for any source of historical market data (local storage, an
 * exchange API, etc). Implementations must not leak their transport or
 * storage details through this interface.
 *
 * @see Candle
 */
interface MarketDataProvider
{
    /**
     * @return Candle[]
     */
    public function getHistoricalCandles(
        string $symbol,
        Timeframe $timeframe,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array;
}
