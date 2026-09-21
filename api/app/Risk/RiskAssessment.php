<?php

namespace App\Risk;

use App\Models\RiskSetting;

/**
 * Immutable result of a risk evaluation: whether a signal is allowed to
 * proceed towards a possible execution, and why.
 *
 * This is a pure data contract for the Risk layer: it carries a decision
 * and its reason, nothing else. It has no knowledge of execution, brokers,
 * or persistence.
 *
 * `positionSize` is only meaningful when `allowed` is true: it is the
 * capital amount the caller should actually deploy for the trade (already
 * capped by any configured {@see RiskSetting} limits), not a
 * quantity of the underlying asset.
 */
final readonly class RiskAssessment
{
    public function __construct(
        public bool $allowed,
        public string $reason,
        public ?string $positionSize = null,
    ) {}
}
