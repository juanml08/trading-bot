<?php

namespace App\Strategy;

use App\MarketData\Candle;
use Carbon\CarbonImmutable;

/**
 * Generates BUY/SELL/HOLD signals from an exponential moving average
 * crossover: a short EMA (5 candles) crossing a long EMA (13 candles).
 *
 * Each EMA series is seeded with the first candle's close and smoothed
 * forward with the standard multiplier `2 / (period + 1)` — a deliberately
 * simple EMA, not a strategy that waits for a full warm-up window before the
 * first value. Uses only closing prices. Knows nothing about brokers, risk,
 * capital, persistence, or execution.
 */
final class EmaCrossoverStrategy implements Strategy
{
    private const int SHORT_PERIOD = 5;

    private const int LONG_PERIOD = 13;

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
                    'Not enough candles to evaluate the EMA crossover: %d available, %d required.',
                    $count,
                    self::LONG_PERIOD + 1,
                ),
                generatedAt: CarbonImmutable::now(),
            );
        }

        $shortEma = $this->exponentialMovingAverages($candles, self::SHORT_PERIOD);
        $longEma = $this->exponentialMovingAverages($candles, self::LONG_PERIOD);

        $previousShortEma = $shortEma[$count - 2];
        $previousLongEma = $longEma[$count - 2];
        $currentShortEma = $shortEma[$count - 1];
        $currentLongEma = $longEma[$count - 1];

        $crossedAbove = bccomp($previousShortEma, $previousLongEma, 18) <= 0
            && bccomp($currentShortEma, $currentLongEma, 18) > 0;

        $crossedBelow = bccomp($previousShortEma, $previousLongEma, 18) >= 0
            && bccomp($currentShortEma, $currentLongEma, 18) < 0;

        if ($crossedAbove) {
            return new Signal(
                type: SignalType::BUY,
                reason: 'Short EMA (5) crossed above long EMA (13).',
                generatedAt: CarbonImmutable::now(),
            );
        }

        if ($crossedBelow) {
            return new Signal(
                type: SignalType::SELL,
                reason: 'Short EMA (5) crossed below long EMA (13).',
                generatedAt: CarbonImmutable::now(),
            );
        }

        return new Signal(
            type: SignalType::HOLD,
            reason: 'No EMA crossover detected between the short (5) and long (13) moving averages.',
            generatedAt: CarbonImmutable::now(),
        );
    }

    /**
     * EMA of `close` for every candle, seeded with the first candle's close.
     *
     * @param  Candle[]  $candles
     * @return string[] indexed the same as $candles
     */
    private function exponentialMovingAverages(array $candles, int $period): array
    {
        $multiplier = bcdiv('2', (string) ($period + 1), 18);
        $oneMinusMultiplier = bcsub('1', $multiplier, 18);

        $emas = [$candles[0]->close];

        for ($i = 1; $i < count($candles); $i++) {
            $emas[$i] = bcadd(
                bcmul($candles[$i]->close, $multiplier, 18),
                bcmul($emas[$i - 1], $oneMinusMultiplier, 18),
                18,
            );
        }

        return $emas;
    }
}
