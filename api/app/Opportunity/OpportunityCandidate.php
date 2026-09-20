<?php

namespace App\Opportunity;

/**
 * A symbol the {@see OpportunityScanner} considers worth handing to the
 * Strategy Pipeline for evaluation.
 *
 * This is a plain data carrier: it does not hold a signal, a direction, or
 * any claim about profitability — only the symbol and the liquidity score
 * used to rank it. Whether it is actually worth trading is decided
 * downstream by Backtesting/Discovery/Validation/Selector.
 */
final readonly class OpportunityCandidate
{
    public function __construct(
        public string $symbol,
        public string $recentVolume,
    ) {}
}
