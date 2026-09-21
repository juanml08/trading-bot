<?php

namespace App\Strategy;

/**
 * Immutable final outcome of running {@see StrategyPipeline}: the candidate
 * {@see StrategySelector} ultimately picked (if any), every
 * {@see ValidationResult} produced along the way, and every
 * {@see DiscoveryResult} produced for TRAIN — one per strategy handed to the
 * pipeline, whether or not it survived Discovery. All three are kept
 * together deliberately: the Selector's own pick collapses to a single
 * candidate or `null`, and without this object that collapse would discard
 * the Discovery/Validation reasoning (which strategies were even considered,
 * and why each one passed or failed at each stage) that produced it.
 */
final readonly class StrategyPipelineResult
{
    /**
     * @param  ValidationResult[]  $validationResults  one per StrategyCandidate that reached
     *                                                 VALIDATION (i.e. survived TRAIN Discovery), in the order
     *                                                 they were evaluated
     * @param  array<string, DiscoveryResult>  $discoveryResults  keyed by strategy name, one per
     *                                                            strategy handed to the pipeline (including those that
     *                                                            never reached VALIDATION), in evaluation order
     */
    public function __construct(
        public ?StrategyCandidate $selectedCandidate,
        public array $validationResults,
        public array $discoveryResults = [],
    ) {}
}
