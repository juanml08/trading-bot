<?php

namespace App\Risk;

use App\Strategy\Signal;
use App\Strategy\SignalType;

/**
 * First, minimal version of the Risk Manager: evaluates whether a signal
 * may proceed towards a possible execution based on available and
 * requested capital.
 *
 * It never executes operations, sizes positions, or applies stop loss,
 * take profit, or any other risk control — those come later.
 */
final class RiskManager
{
    public function evaluate(Signal $signal, string $availableCapital, string $capitalToUse): RiskAssessment
    {
        if ($signal->type === SignalType::HOLD) {
            return new RiskAssessment(
                allowed: false,
                reason: 'Signal is HOLD: there is no entry or exit intention to evaluate.',
            );
        }

        if (bccomp($availableCapital, '0', 18) <= 0) {
            return new RiskAssessment(
                allowed: false,
                reason: "Available capital ({$availableCapital}) must be greater than zero.",
            );
        }

        if (bccomp($capitalToUse, '0', 18) <= 0) {
            return new RiskAssessment(
                allowed: false,
                reason: "Capital to use ({$capitalToUse}) must be greater than zero.",
            );
        }

        if (bccomp($capitalToUse, $availableCapital, 18) > 0) {
            return new RiskAssessment(
                allowed: false,
                reason: "Capital to use ({$capitalToUse}) exceeds available capital ({$availableCapital}).",
            );
        }

        return new RiskAssessment(
            allowed: true,
            reason: "Capital to use ({$capitalToUse}) is within available capital ({$availableCapital}).",
        );
    }
}
