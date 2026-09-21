<?php

namespace App\Risk;

use App\Models\RiskSetting;
use App\Strategy\Signal;
use App\Strategy\SignalType;

/**
 * Evaluates whether a signal may proceed towards a possible execution based
 * on available and requested capital and, when the account has one, its
 * {@see RiskSetting}.
 *
 * When a `RiskSetting` is supplied, the requested capital is capped by its
 * `max_risk_per_trade` (a percentage of available capital) and
 * `max_capital_per_trade` limits, and the trade is rejected outright if the
 * account already has `max_open_trades` open trades. Without a `RiskSetting`
 * (no row configured for the account yet), only the base capital checks
 * apply — this keeps existing accounts working exactly as before Phase 5
 * until they opt into explicit risk limits.
 *
 * It never executes operations or applies stop loss / take profit — those
 * remain out of scope until a Stop Loss concept exists (see Phase 5 notes).
 */
final class RiskManager
{
    public function evaluate(
        Signal $signal,
        string $availableCapital,
        string $capitalToUse,
        ?RiskSetting $riskSetting = null,
        int $openTradesCount = 0,
    ): RiskAssessment {
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

        if ($riskSetting !== null && $openTradesCount >= $riskSetting->max_open_trades) {
            return new RiskAssessment(
                allowed: false,
                reason: "Existing exposure too high: {$openTradesCount} open trade(s) already reached the configured limit of {$riskSetting->max_open_trades}.",
            );
        }

        $positionSize = $capitalToUse;

        if ($riskSetting !== null) {
            $maxByRiskPercent = bcdiv(bcmul($availableCapital, (string) $riskSetting->max_risk_per_trade, 18), '100', 18);
            $positionSize = $this->min($positionSize, $maxByRiskPercent);
            $positionSize = $this->min($positionSize, (string) $riskSetting->max_capital_per_trade);

            if (bccomp($positionSize, '0', 18) <= 0) {
                return new RiskAssessment(
                    allowed: false,
                    reason: 'Risk limit exceeded: the configured risk percentage leaves no capital available for this trade.',
                );
            }
        }

        return new RiskAssessment(
            allowed: true,
            reason: "Capital to use ({$capitalToUse}) is within available capital ({$availableCapital}).",
            positionSize: $positionSize,
        );
    }

    private function min(string $a, string $b): string
    {
        return bccomp($a, $b, 18) <= 0 ? $a : $b;
    }
}
