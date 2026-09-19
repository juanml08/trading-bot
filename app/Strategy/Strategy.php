<?php

namespace App\Strategy;

use App\MarketData\Candle;

/**
 * Contract for generating a trading signal from historical candles.
 * Implementations must not decide whether a signal should be executed, size
 * positions, or manage risk/capital — that belongs to RiskManager, Broker,
 * and StrategyEvaluator.
 */
interface Strategy
{
    /**
     * @param  Candle[]  $candles  chronologically ordered (oldest first)
     */
    public function generate(array $candles): Signal;
}
