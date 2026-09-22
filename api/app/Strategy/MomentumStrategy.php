<?php

namespace App\Strategy;

use App\MarketData\Candle;
use Carbon\CarbonImmutable;

/**
 * Generates BUY/SELL/HOLD signals from the Rate of Change (ROC) momentum
 * indicator: the percentage change between the current close and the close
 * PERIOD candles ago. Unlike {@see SimpleMovingAverageStrategy},
 * {@see SmaCrossoverStrategy}, and {@see EmaCrossoverStrategy}, this compares
 * price directly against its own past value instead of comparing two moving
 * averages against each other.
 *
 * Signals on the momentum flipping direction: BUY when ROC crosses from
 * negative/zero to positive (price starting to gain against its past self),
 * SELL when ROC crosses from positive/zero to negative. Uses only closing
 * prices. Knows nothing about brokers, risk, capital, persistence, or
 * execution.
 */
final class MomentumStrategy implements Strategy
{
    private const int PERIOD = 10;

    /**
     * @param  Candle[]  $candles  chronologically ordered (oldest first)
     */
    public function generate(array $candles): Signal
    {
        $count = count($candles);

        // One extra candle beyond PERIOD + 1 is needed to have a previous
        // ROC value to compare the current one against for a zero cross.
        if ($count < self::PERIOD + 2) {
            return new Signal(
                type: SignalType::HOLD,
                reason: sprintf(
                    'Not enough candles to evaluate the momentum (ROC): %d available, %d required.',
                    $count,
                    self::PERIOD + 2,
                ),
                generatedAt: CarbonImmutable::now(),
            );
        }

        $previousRoc = $this->rateOfChange($candles, offset: 1);
        $currentRoc = $this->rateOfChange($candles, offset: 0);

        $crossedAboveZero = bccomp($previousRoc, '0', 18) <= 0
            && bccomp($currentRoc, '0', 18) > 0;

        $crossedBelowZero = bccomp($previousRoc, '0', 18) >= 0
            && bccomp($currentRoc, '0', 18) < 0;

        if ($crossedAboveZero) {
            return new Signal(
                type: SignalType::BUY,
                reason: 'Momentum (ROC-'.self::PERIOD.') crossed above zero.',
                generatedAt: CarbonImmutable::now(),
            );
        }

        if ($crossedBelowZero) {
            return new Signal(
                type: SignalType::SELL,
                reason: 'Momentum (ROC-'.self::PERIOD.') crossed below zero.',
                generatedAt: CarbonImmutable::now(),
            );
        }

        return new Signal(
            type: SignalType::HOLD,
            reason: 'Momentum (ROC-'.self::PERIOD.') did not cross zero.',
            generatedAt: CarbonImmutable::now(),
        );
    }

    /**
     * Percentage change between the close $offset candles before the most
     * recent one and the close PERIOD candles before that.
     *
     * @param  Candle[]  $candles
     */
    private function rateOfChange(array $candles, int $offset): string
    {
        $index = count($candles) - 1 - $offset;
        $current = $candles[$index]->close;
        $past = $candles[$index - self::PERIOD]->close;

        return bcmul(bcdiv(bcsub($current, $past, 18), $past, 18), '100', 18);
    }
}
