<?php

namespace App\Strategy;

use App\MarketData\Candle;
use Carbon\CarbonImmutable;

/**
 * Generates BUY/SELL/HOLD signals from a moving average crossover between a
 * configurable short and long SMA window. This is the same algorithm as
 * {@see SimpleMovingAverageStrategy} (kept untouched as the fixed 5/10
 * reference implementation), generalized so a second, differently-tuned SMA
 * variant does not have to duplicate the crossover math.
 *
 * Uses only closing prices. Knows nothing about brokers, risk, capital,
 * persistence, or execution.
 */
final readonly class SmaCrossoverStrategy implements Strategy
{
    public function __construct(
        private int $shortPeriod,
        private int $longPeriod,
    ) {}

    /**
     * @param  Candle[]  $candles  chronologically ordered (oldest first)
     */
    public function generate(array $candles): Signal
    {
        $count = count($candles);

        if ($count < $this->longPeriod + 1) {
            return new Signal(
                type: SignalType::HOLD,
                reason: sprintf(
                    'Not enough candles to evaluate the SMA crossover: %d available, %d required.',
                    $count,
                    $this->longPeriod + 1,
                ),
                generatedAt: CarbonImmutable::now(),
            );
        }

        $previousShortSma = $this->simpleMovingAverage($candles, $this->shortPeriod, offset: 1);
        $previousLongSma = $this->simpleMovingAverage($candles, $this->longPeriod, offset: 1);
        $currentShortSma = $this->simpleMovingAverage($candles, $this->shortPeriod, offset: 0);
        $currentLongSma = $this->simpleMovingAverage($candles, $this->longPeriod, offset: 0);

        $crossedAbove = bccomp($previousShortSma, $previousLongSma, 18) <= 0
            && bccomp($currentShortSma, $currentLongSma, 18) > 0;

        $crossedBelow = bccomp($previousShortSma, $previousLongSma, 18) >= 0
            && bccomp($currentShortSma, $currentLongSma, 18) < 0;

        if ($crossedAbove) {
            return new Signal(
                type: SignalType::BUY,
                reason: "Short SMA ({$this->shortPeriod}) crossed above long SMA ({$this->longPeriod}).",
                generatedAt: CarbonImmutable::now(),
            );
        }

        if ($crossedBelow) {
            return new Signal(
                type: SignalType::SELL,
                reason: "Short SMA ({$this->shortPeriod}) crossed below long SMA ({$this->longPeriod}).",
                generatedAt: CarbonImmutable::now(),
            );
        }

        return new Signal(
            type: SignalType::HOLD,
            reason: "No SMA crossover detected between the short ({$this->shortPeriod}) and long ({$this->longPeriod}) moving averages.",
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
