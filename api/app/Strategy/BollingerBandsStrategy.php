<?php

namespace App\Strategy;

use App\MarketData\Candle;
use Carbon\CarbonImmutable;

/**
 * Generates BUY/SELL/HOLD signals from Bollinger Bands: a middle SMA plus an
 * upper/lower band offset by a multiple of the rolling standard deviation of
 * closing prices. Unlike {@see SimpleMovingAverageStrategy},
 * {@see SmaCrossoverStrategy}, and {@see EmaCrossoverStrategy}, this reacts to
 * price versus a volatility band rather than one moving average crossing
 * another.
 *
 * Signals a mean-reversion bounce: BUY when price closes back above the
 * lower band after having closed at or below it (a rebound from an oversold
 * extreme), SELL when price closes back below the upper band after having
 * closed at or above it (a pullback from an overbought extreme). Uses only
 * closing prices. Knows nothing about brokers, risk, capital, persistence,
 * or execution.
 */
final class BollingerBandsStrategy implements Strategy
{
    private const int PERIOD = 20;

    private const string STANDARD_DEVIATIONS = '2';

    /**
     * @param  Candle[]  $candles  chronologically ordered (oldest first)
     */
    public function generate(array $candles): Signal
    {
        $count = count($candles);

        if ($count < self::PERIOD + 1) {
            return new Signal(
                type: SignalType::HOLD,
                reason: sprintf(
                    'Not enough candles to evaluate the Bollinger Bands: %d available, %d required.',
                    $count,
                    self::PERIOD + 1,
                ),
                generatedAt: CarbonImmutable::now(),
            );
        }

        [$previousLower, $previousUpper] = $this->bands($candles, offset: 1);
        [$currentLower, $currentUpper] = $this->bands($candles, offset: 0);

        $previousClose = $candles[$count - 2]->close;
        $currentClose = $candles[$count - 1]->close;

        $bouncedOffLowerBand = bccomp($previousClose, $previousLower, 18) <= 0
            && bccomp($currentClose, $currentLower, 18) > 0;

        $rejectedAtUpperBand = bccomp($previousClose, $previousUpper, 18) >= 0
            && bccomp($currentClose, $currentUpper, 18) < 0;

        if ($bouncedOffLowerBand) {
            return new Signal(
                type: SignalType::BUY,
                reason: 'Price closed back above the lower Bollinger Band ('.self::PERIOD.', '.self::STANDARD_DEVIATIONS.'σ) after closing at or below it.',
                generatedAt: CarbonImmutable::now(),
            );
        }

        if ($rejectedAtUpperBand) {
            return new Signal(
                type: SignalType::SELL,
                reason: 'Price closed back below the upper Bollinger Band ('.self::PERIOD.', '.self::STANDARD_DEVIATIONS.'σ) after closing at or above it.',
                generatedAt: CarbonImmutable::now(),
            );
        }

        return new Signal(
            type: SignalType::HOLD,
            reason: 'Price did not bounce off the lower or upper Bollinger Band ('.self::PERIOD.', '.self::STANDARD_DEVIATIONS.'σ).',
            generatedAt: CarbonImmutable::now(),
        );
    }

    /**
     * The [lower, upper] Bollinger Band around the SMA(PERIOD) ending
     * $offset candles before the most recent one.
     *
     * @param  Candle[]  $candles
     * @return array{0: string, 1: string}
     */
    private function bands(array $candles, int $offset): array
    {
        $end = count($candles) - $offset;
        $window = array_slice($candles, $end - self::PERIOD, self::PERIOD);

        $middle = $this->simpleMovingAverage($window);
        $deviation = $this->standardDeviation($window, $middle);
        $offsetValue = bcmul($deviation, self::STANDARD_DEVIATIONS, 18);

        return [
            bcsub($middle, $offsetValue, 18),
            bcadd($middle, $offsetValue, 18),
        ];
    }

    /**
     * @param  Candle[]  $window
     */
    private function simpleMovingAverage(array $window): string
    {
        $sum = '0';
        foreach ($window as $candle) {
            $sum = bcadd($sum, $candle->close, 18);
        }

        return bcdiv($sum, (string) count($window), 18);
    }

    /**
     * Population standard deviation of `close` over $window, around the
     * already-computed mean $middle.
     *
     * @param  Candle[]  $window
     */
    private function standardDeviation(array $window, string $middle): string
    {
        $sumSquaredDeviations = '0';
        foreach ($window as $candle) {
            $deviation = bcsub($candle->close, $middle, 18);
            $sumSquaredDeviations = bcadd($sumSquaredDeviations, bcmul($deviation, $deviation, 18), 18);
        }

        $variance = bcdiv($sumSquaredDeviations, (string) count($window), 18);

        return bcsqrt($variance, 18);
    }
}
