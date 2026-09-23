<?php

namespace App\Strategy;

use App\MarketData\Candle;
use Carbon\CarbonImmutable;

/**
 * Generates BUY/SELL/HOLD signals from an exponential moving average
 * crossover between a configurable short and long EMA window, defaulting to
 * a short EMA (5 candles) crossing a long EMA (13 candles) — the same
 * default this class always used before its periods became configurable
 * (see {@see StrategyCatalog::discoveryCandidates()} for the
 * wider set of period variations Discover evaluates).
 *
 * Each EMA series is seeded with the first candle's close and smoothed
 * forward with the standard multiplier `2 / (period + 1)` — a deliberately
 * simple EMA, not a strategy that waits for a full warm-up window before the
 * first value. Uses only closing prices. Knows nothing about brokers, risk,
 * capital, persistence, or execution.
 *
 * {@see StrategyEvaluator} calls `generate()` once per candle with an
 * ever-growing prefix of the same candle sequence (`array_slice($candles, 0,
 * $index + 1)`), so recomputing each EMA series from scratch every call is
 * quadratic in the number of candles. This caches the last EMA series it
 * computed and, when the new call is an append-only extension of that same
 * sequence (checked cheaply via object identity on the previously-last
 * candle — {@see Candle} is an immutable value object, and callers never
 * clone candles between slices), extends it instead of recomputing. Any
 * other call pattern (a shorter slice, a different candle sequence) falls
 * back to a full recompute, so this is purely a performance cache — it
 * never changes the result.
 */
final class EmaCrossoverStrategy implements Strategy
{
    /** @var string[] */
    private array $shortEmaCache = [];

    /** @var string[] */
    private array $longEmaCache = [];

    private ?Candle $cachedLastCandle = null;

    /** @var array<int, array{0: string, 1: string}> multiplier/oneMinusMultiplier by period */
    private array $multiplierCache = [];

    public function __construct(
        private readonly int $shortPeriod = 5,
        private readonly int $longPeriod = 13,
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
                    'Not enough candles to evaluate the EMA crossover: %d available, %d required.',
                    $count,
                    $this->longPeriod + 1,
                ),
                generatedAt: CarbonImmutable::now(),
            );
        }

        $canExtendCache = $this->cachedLastCandle !== null
            && count($this->shortEmaCache) <= $count
            && $candles[count($this->shortEmaCache) - 1] === $this->cachedLastCandle;

        $shortEma = $this->exponentialMovingAverages($candles, $this->shortPeriod, $canExtendCache ? $this->shortEmaCache : []);
        $longEma = $this->exponentialMovingAverages($candles, $this->longPeriod, $canExtendCache ? $this->longEmaCache : []);

        $this->shortEmaCache = $shortEma;
        $this->longEmaCache = $longEma;
        $this->cachedLastCandle = $candles[$count - 1];

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
                reason: "Short EMA ({$this->shortPeriod}) crossed above long EMA ({$this->longPeriod}).",
                generatedAt: CarbonImmutable::now(),
            );
        }

        if ($crossedBelow) {
            return new Signal(
                type: SignalType::SELL,
                reason: "Short EMA ({$this->shortPeriod}) crossed below long EMA ({$this->longPeriod}).",
                generatedAt: CarbonImmutable::now(),
            );
        }

        return new Signal(
            type: SignalType::HOLD,
            reason: "No EMA crossover detected between the short ({$this->shortPeriod}) and long ({$this->longPeriod}) moving averages.",
            generatedAt: CarbonImmutable::now(),
        );
    }

    /**
     * EMA of `close` for every candle, seeded with the first candle's close.
     * When $cached is a valid EMA series for a prefix of $candles, extends it
     * instead of recomputing the whole thing.
     *
     * @param  Candle[]  $candles
     * @param  string[]  $cached  EMA series already computed for the first
     *                            count($cached) candles, or [] to recompute
     *                            from scratch
     * @return string[] indexed the same as $candles
     */
    private function exponentialMovingAverages(array $candles, int $period, array $cached): array
    {
        if (! isset($this->multiplierCache[$period])) {
            $multiplier = bcdiv('2', (string) ($period + 1), 18);
            $this->multiplierCache[$period] = [$multiplier, bcsub('1', $multiplier, 18)];
        }

        [$multiplier, $oneMinusMultiplier] = $this->multiplierCache[$period];

        if ($cached !== []) {
            $emas = $cached;
            $start = count($cached);
        } else {
            $emas = [$candles[0]->close];
            $start = 1;
        }

        for ($i = $start; $i < count($candles); $i++) {
            $emas[$i] = bcadd(
                bcmul($candles[$i]->close, $multiplier, 18),
                bcmul($emas[$i - 1], $oneMinusMultiplier, 18),
                18,
            );
        }

        return $emas;
    }
}
