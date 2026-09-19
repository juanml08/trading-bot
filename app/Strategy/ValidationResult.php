<?php

namespace App\Strategy;

use InvalidArgumentException;

/**
 * Immutable result of validating a {@see StrategyCandidate} — a strategy
 * that already passed {@see StrategyDiscovery} during TRAIN — against a new
 * evaluation over VALIDATION candles.
 *
 * This is a pure data container: it carries the TRAIN candidate (unchanged,
 * with its own TRAIN {@see StrategyEvaluation} untouched), an independent
 * VALIDATION {@see StrategyEvaluation}, and a verdict already decided
 * elsewhere. It does not run the evaluator, does not check criteria, and
 * does not compare TRAIN against VALIDATION — whichever piece orchestrates
 * Validation is responsible for computing `$passed` and `$failedCriteria`
 * before constructing this object.
 */
final readonly class ValidationResult
{
    /**
     * @param  string[]  $failedCriteria  stable criterion names (e.g.
     *                                    'minimumTrades', 'minimumWinRate', 'maximumDrawdown',
     *                                    'minimumProfitLoss') that the VALIDATION evaluation did not
     *                                    meet. Empty when $passed is true.
     */
    public function __construct(
        public StrategyCandidate $candidate,
        public StrategyEvaluation $validationEvaluation,
        public bool $passed,
        public array $failedCriteria = [],
    ) {
        if ($passed && $failedCriteria !== []) {
            throw new InvalidArgumentException(
                'A passed ValidationResult cannot list failed criteria.'
            );
        }

        if (! $passed && $failedCriteria === []) {
            throw new InvalidArgumentException(
                'A failed ValidationResult must list at least one failed criterion.'
            );
        }
    }
}
