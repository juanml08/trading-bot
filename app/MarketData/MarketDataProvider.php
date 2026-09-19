<?php

namespace App\MarketData;

use App\Strategy\TrainValidationSplit;
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
     * Implementations must return candles in chronological ascending order
     * (oldest first, most recent last). This is a precondition relied upon
     * by consumers such as {@see TrainValidationSplit}, which
     * is purely positional and does not sort or inspect timestamps.
     *
     * @return Candle[]
     */
    public function getHistoricalCandles(
        string $symbol,
        Timeframe $timeframe,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array;
}
