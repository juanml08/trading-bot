<?php

namespace App\MarketData;

use Carbon\CarbonImmutable;

/**
 * Immutable representation of a single OHLCV candle for a symbol/timeframe.
 *
 * This is a pure data contract for the Market Data layer: it carries price
 * data only and has no knowledge of strategies, signals, risk, or brokers.
 */
final readonly class Candle
{
    public function __construct(
        public string $symbol,
        public Timeframe $timeframe,
        public CarbonImmutable $timestamp,
        public string $open,
        public string $high,
        public string $low,
        public string $close,
        public string $volume,
    ) {}
}
