<?php

namespace App\Strategy;

/**
 * Immutable result of evaluating a strategy against a historical set of
 * candles: aggregated performance metrics only. It has no knowledge of how
 * those metrics were computed, risk, execution, or persistence.
 *
 * `finalCapital` marks any still-open position to market using the last
 * available close price — it is a valuation for reporting purposes only,
 * not a simulated sale, so it never affects trade counts, win rate, or
 * profit/loss totals. Use `hasOpenPositionAtEnd` to know whether that
 * happened.
 *
 * `profitLoss`, `profitLossPercentage`, and `finalCapital` are net of the
 * commission and slippage costs applied by {@see StrategyEvaluator} (zero
 * by default). `grossProfitLoss` is what `profitLoss` would have been
 * without those costs, and `totalCosts` is the dollar amount lost to them
 * across every executed trade, including the entry cost already paid on
 * any position still open at the end. They always satisfy:
 * `grossProfitLoss = profitLoss + totalCosts`.
 *
 * `profitFactor` is the ratio of `totalProfit` to `totalLoss` (i.e. of the
 * winning trades' combined P&L to the losing trades' combined P&L,
 * net of costs) — it is an informative metric only and does not affect
 * any other field. It is `'INF'` when there are no losing trades and at
 * least one winning trade, and `'0'` when there are no closed trades or no
 * winning trades.
 */
final readonly class StrategyEvaluation
{
    public function __construct(
        public string $initialCapital,
        public string $finalCapital,
        public string $profitLoss,
        public string $profitLossPercentage,
        public int $totalTrades,
        public int $winningTrades,
        public int $losingTrades,
        public string $winRate,
        public string $totalProfit,
        public string $totalLoss,
        public string $maxDrawdownPercentage,
        public bool $hasOpenPositionAtEnd,
        public ?string $openPositionQuantity = null,
        public ?string $openPositionEntryPrice = null,
        public string $grossProfitLoss = '0',
        public string $totalCosts = '0',
        public string $profitFactor = '0',
    ) {}
}
