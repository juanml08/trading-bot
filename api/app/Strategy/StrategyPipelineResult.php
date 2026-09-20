<?php

namespace App\Strategy;

/**
 * Immutable final outcome of running {@see StrategyPipeline}: the candidate
 * {@see StrategySelector} ultimately picked (if any), and every
 * {@see ValidationResult} produced along the way. Both are kept together
 * deliberately — the Selector's own pick collapses to a single candidate or
 * `null`, and without this object that collapse would discard the
 * Validation reasoning (which candidates were even considered, and why each
 * one passed or failed) that produced it.
 */
final readonly class StrategyPipelineResult
{
    /**
     * @param  ValidationResult[]  $validationResults  one per StrategyCandidate that reached
     *                                                 VALIDATION (i.e. survived TRAIN Discovery), in the order
     *                                                 they were evaluated
     */
    public function __construct(
        public ?StrategyCandidate $selectedCandidate,
        public array $validationResults,
    ) {}
}
