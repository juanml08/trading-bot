<?php

namespace App\Strategy;

/**
 * A strategy evaluation that passed {@see StrategyDiscovery}'s minimum
 * viability criteria. It only identifies the strategy and carries its
 * evaluation — it does not rank, score, or judge how good a candidate is
 * relative to another.
 */
final readonly class StrategyCandidate
{
    public function __construct(
        public string $strategyName,
        public StrategyEvaluation $evaluation,
    ) {}
}
