<?php

namespace App\Strategy;

use App\MarketData\Candle;
use Carbon\CarbonImmutable;

/**
 * Generates BUY/SELL/HOLD signals from a moving average crossover:
 * a short SMA (5 candles) crossing a long SMA (10 candles).
 *
 * Uses only closing prices. Knows nothing about brokers, risk, capital,
 * persistence, or execution.
 */
final class SimpleMovingAverageStrategy implements Strategy
{
    private const int SHORT_PERIOD = 5;

    private const int LONG_PERIOD = 10;

    /**
     * @param  Candle[]  $candles  chronologically ordered (oldest first)
     */
    public function generate(array $candles): Signal
    {
        $count = count($candles);

        if ($count < self::LONG_PERIOD + 1) {
            return new Signal(
                type: SignalType::HOLD,
                reason: sprintf(
                    'Not enough candles to evaluate the SMA crossover: %d available, %d required.',
                    $count,
                    self::LONG_PERIOD + 1,
                ),
                generatedAt: CarbonImmutable::now(),
            );
        }

        $previousShortSma = $this->simpleMovingAverage($candles, self::SHORT_PERIOD, offset: 1);
        $previousLongSma = $this->simpleMovingAverage($candles, self::LONG_PERIOD, offset: 1);
        $currentShortSma = $this->simpleMovingAverage($candles, self::SHORT_PERIOD, offset: 0);
        $currentLongSma = $this->simpleMovingAverage($candles, self::LONG_PERIOD, offset: 0);

        $crossedAbove = bccomp($previousShortSma, $previousLongSma, 18) <= 0
            && bccomp($currentShortSma, $currentLongSma, 18) > 0;

        $crossedBelow = bccomp($previousShortSma, $previousLongSma, 18) >= 0
            && bccomp($currentShortSma, $currentLongSma, 18) < 0;

        if ($crossedAbove) {
            return new Signal(
                type: SignalType::BUY,
                reason: 'Short SMA (5) crossed above long SMA (10).',
                generatedAt: CarbonImmutable::now(),
            );
        }

        if ($crossedBelow) {
            return new Signal(
                type: SignalType::SELL,
                reason: 'Short SMA (5) crossed below long SMA (10).',
                generatedAt: CarbonImmutable::now(),
            );
        }

        return new Signal(
            type: SignalType::HOLD,
            reason: 'No SMA crossover detected between the short (5) and long (10) moving averages.',
            generatedAt: CarbonImmutable::now(),
        );
    }

    /**
     * Average of `close` over the last $period candles, ending $offset
     * candles before the most recent one.
     *
     * @param  Candle[]  $candles
     */
    private function simpleMovingAverage(array $candles, int $period, int $offset): string
    {
        $end = count($candles) - $offset;
        $window = array_slice($candles, $end - $period, $period);

        $sum = '0';
        foreach ($window as $candle) {
            $sum = bcadd($sum, $candle->close, 18);
        }

        return bcdiv($sum, (string) $period, 18);
    }
}
