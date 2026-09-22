<?php

namespace App\Strategy;

use App\MarketData\Candle;
use Carbon\CarbonImmutable;

/**
 * Generates BUY/SELL/HOLD signals from the Relative Strength Index (RSI): an
 * oscillator between 0 and 100 built from average gains vs. average losses
 * over a fixed period, not from a moving-average crossover like
 * {@see SimpleMovingAverageStrategy}, {@see SmaCrossoverStrategy}, or
 * {@see EmaCrossoverStrategy}.
 *
 * Uses Wilder's smoothing (a running average of gains/losses, re-seeded from
 * a plain average of the first period) and signals on the classic
 * oversold/overbought threshold cross: BUY when RSI crosses back above 30
 * (recovering from oversold), SELL when RSI crosses back below 70
 * (retreating from overbought). Uses only closing prices. Knows nothing
 * about brokers, risk, capital, persistence, or execution.
 */
final class RelativeStrengthIndexStrategy implements Strategy
{
    private const int PERIOD = 14;

    private const string OVERSOLD_THRESHOLD = '30';

    private const string OVERBOUGHT_THRESHOLD = '70';

    /**
     * @param  Candle[]  $candles  chronologically ordered (oldest first)
     */
    public function generate(array $candles): Signal
    {
        $count = count($candles);

        // One extra candle is needed beyond PERIOD to have a previous RSI
        // value to compare the current one against for a threshold cross.
        if ($count < self::PERIOD + 2) {
            return new Signal(
                type: SignalType::HOLD,
                reason: sprintf(
                    'Not enough candles to evaluate the RSI: %d available, %d required.',
                    $count,
                    self::PERIOD + 2,
                ),
                generatedAt: CarbonImmutable::now(),
            );
        }

        $rsiSeries = $this->relativeStrengthIndexSeries($candles);

        // $rsiSeries is keyed by candle index (only populated from
        // self::PERIOD onward), not densely from 0 — count($rsiSeries) is
        // the number of computed values, not the last candle index.
        $previousRsi = $rsiSeries[$count - 2];
        $currentRsi = $rsiSeries[$count - 1];

        $crossedAboveOversold = bccomp($previousRsi, self::OVERSOLD_THRESHOLD, 18) <= 0
            && bccomp($currentRsi, self::OVERSOLD_THRESHOLD, 18) > 0;

        $crossedBelowOverbought = bccomp($previousRsi, self::OVERBOUGHT_THRESHOLD, 18) >= 0
            && bccomp($currentRsi, self::OVERBOUGHT_THRESHOLD, 18) < 0;

        if ($crossedAboveOversold) {
            return new Signal(
                type: SignalType::BUY,
                reason: 'RSI ('.self::PERIOD.') crossed back above the oversold threshold (30).',
                generatedAt: CarbonImmutable::now(),
            );
        }

        if ($crossedBelowOverbought) {
            return new Signal(
                type: SignalType::SELL,
                reason: 'RSI ('.self::PERIOD.') crossed back below the overbought threshold (70).',
                generatedAt: CarbonImmutable::now(),
            );
        }

        return new Signal(
            type: SignalType::HOLD,
            reason: 'RSI ('.self::PERIOD.') did not cross the oversold (30) or overbought (70) threshold.',
            generatedAt: CarbonImmutable::now(),
        );
    }

    /**
     * RSI for every candle from index PERIOD onward, using Wilder's
     * smoothing: the first average gain/loss is a plain average over the
     * first PERIOD changes, and every value after that smooths the previous
     * average with the new change instead of averaging over a sliding
     * window.
     *
     * @param  Candle[]  $candles
     * @return array<int, string> RSI values indexed the same as $candles,
     *                            only populated from index PERIOD onward
     */
    private function relativeStrengthIndexSeries(array $candles): array
    {
        $gains = [];
        $losses = [];

        for ($i = 1; $i < count($candles); $i++) {
            $change = bcsub($candles[$i]->close, $candles[$i - 1]->close, 18);

            $gains[$i] = bccomp($change, '0', 18) > 0 ? $change : '0';
            $losses[$i] = bccomp($change, '0', 18) < 0 ? bcmul($change, '-1', 18) : '0';
        }

        $averageGain = $this->average($gains, 1, self::PERIOD);
        $averageLoss = $this->average($losses, 1, self::PERIOD);

        $rsi = [];
        $rsi[self::PERIOD] = $this->relativeStrengthIndex($averageGain, $averageLoss);

        for ($i = self::PERIOD + 1; $i < count($candles); $i++) {
            $averageGain = bcdiv(
                bcadd(bcmul($averageGain, (string) (self::PERIOD - 1), 18), $gains[$i], 18),
                (string) self::PERIOD,
                18,
            );

            $averageLoss = bcdiv(
                bcadd(bcmul($averageLoss, (string) (self::PERIOD - 1), 18), $losses[$i], 18),
                (string) self::PERIOD,
                18,
            );

            $rsi[$i] = $this->relativeStrengthIndex($averageGain, $averageLoss);
        }

        return $rsi;
    }

    private function relativeStrengthIndex(string $averageGain, string $averageLoss): string
    {
        if (bccomp($averageLoss, '0', 18) === 0) {
            return '100';
        }

        $relativeStrength = bcdiv($averageGain, $averageLoss, 18);

        return bcsub('100', bcdiv('100', bcadd('1', $relativeStrength, 18), 18), 18);
    }

    /**
     * @param  array<int, string>  $values  indexed like $gains/$losses (from index 1)
     */
    private function average(array $values, int $from, int $count): string
    {
        $sum = '0';
        for ($i = $from; $i < $from + $count; $i++) {
            $sum = bcadd($sum, $values[$i], 18);
        }

        return bcdiv($sum, (string) $count, 18);
    }
}
