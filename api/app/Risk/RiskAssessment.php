<?php

namespace App\Risk;

/**
 * Immutable result of a risk evaluation: whether a signal is allowed to
 * proceed towards a possible execution, and why.
 *
 * This is a pure data contract for the Risk layer: it carries a decision
 * and its reason, nothing else. It has no knowledge of execution, brokers,
 * or persistence.
 */
final readonly class RiskAssessment
{
    public function __construct(
        public bool $allowed,
        public string $reason,
    ) {}
}
