<?php

namespace App\Strategy;

use InvalidArgumentException;

/**
 * Immutable result of running {@see StrategyDiscovery}'s minimum viability
 * criteria against one strategy's TRAIN {@see StrategyEvaluation} —
 * regardless of whether it passed. `StrategyDiscovery::discover()` itself
 * only returns the strategies that passed (as {@see StrategyCandidate}s),
 * discarding the TRAIN evaluation of everything else; this type is how
 * {@see StrategyPipeline} keeps that TRAIN-stage outcome, for every strategy
 * that was evaluated, instead of losing it once a strategy fails Discovery.
 *
 * This is a pure data container, exactly like {@see ValidationResult}: it
 * does not run the evaluator or check criteria itself.
 */
final readonly class DiscoveryResult
{
    /**
     * @param  string[]  $failedCriteria  stable criterion names (e.g.
     *                                    'minimumTrades', 'minimumWinRate', 'maximumDrawdown',
     *                                    'minimumProfitLoss') that the TRAIN evaluation did not meet.
     *                                    Empty when $passed is true.
     */
    public function __construct(
        public string $strategyName,
        public StrategyEvaluation $trainEvaluation,
        public bool $passed,
        public array $failedCriteria = [],
    ) {
        if ($passed && $failedCriteria !== []) {
            throw new InvalidArgumentException(
                'A passed DiscoveryResult cannot list failed criteria.'
            );
        }

        if (! $passed && $failedCriteria === []) {
            throw new InvalidArgumentException(
                'A failed DiscoveryResult must list at least one failed criterion.'
            );
        }
    }
}
