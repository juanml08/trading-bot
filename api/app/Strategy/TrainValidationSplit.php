<?php

namespace App\Strategy;

use App\MarketData\Candle;
use InvalidArgumentException;

/**
 * Splits a chronologically ordered set of candles into two non-overlapping
 * chronological windows for out-of-sample validation: TRAIN (the earlier
 * candles) and VALIDATION (the later candles).
 *
 * The split is purely positional: it trusts the caller's ordering — candles
 * are documented elsewhere as oldest-first (see {@see Strategy::generate()}
 * and {@see StrategyEvaluator::evaluate()}) — and never inspects, compares,
 * or sorts by `Candle::$timestamp`. It has no knowledge of strategies,
 * evaluation, discovery, selection, or where the candles came from.
 *
 * The cutoff index is `floor(count($candles) * $trainPercentage / 100)`,
 * then clamped to the range `[1, count($candles) - 1]`. That clamp is what
 * guarantees both TRAIN and VALIDATION end up with at least one candle
 * whenever there are at least two, even if the exact requested percentage
 * cannot be honored for very small inputs (e.g. 3 candles at 1% still
 * yields 1 TRAIN / 2 VALIDATION rather than 0 TRAIN / 3 VALIDATION). With
 * exactly 1 candle, the same clamp assigns it entirely to TRAIN, leaving
 * VALIDATION empty — there is no split that gives both sides an element.
 * With 0 candles, both sides are empty.
 */
final class TrainValidationSplit
{
    public function __construct(
        private readonly int $trainPercentage,
    ) {
        if ($trainPercentage < 1 || $trainPercentage > 99) {
            throw new InvalidArgumentException(
                "Train percentage ({$trainPercentage}) must be between 1 and 99."
            );
        }
    }

    /**
     * @param  Candle[]  $candles  chronologically ordered (oldest first)
     * @return array{train: Candle[], validation: Candle[]}
     */
    public function split(array $candles): array
    {
        if ($candles === []) {
            return ['train' => [], 'validation' => []];
        }

        $count = count($candles);
        $rawCutoff = intdiv($count * $this->trainPercentage, 100);
        $cutoff = max(1, min($rawCutoff, $count - 1));

        return [
            'train' => array_slice($candles, 0, $cutoff),
            'validation' => array_slice($candles, $cutoff),
        ];
    }
}
