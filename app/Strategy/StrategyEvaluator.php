<?php

namespace App\Strategy;

use App\MarketData\Candle;

/**
 * Evaluates how a strategy would have performed over a historical set of
 * candles and reduces that walk into aggregated metrics.
 *
 * This is a historical evaluation, not execution: it never uses Broker or
 * PaperAccount, does not manage risk, and does not persist anything. At
 * most one position is held at a time, opened/closed using each candle's
 * `close` price:
 *
 * - BUY opens a position using all available cash, only if none is open.
 * - SELL closes the open position, only if one is open.
 * - BUY while a position is open, or SELL with none open, is ignored.
 * - A position still open after the last candle is left open — no sale is
 *   invented for it, and it is not counted as a trade (see
 *   {@see StrategyEvaluation}).
 *
 * Commission and slippage are configured here, in the simulated-execution
 * context — the Strategy and the candles it reads never know about them:
 *
 * - `$commissionRate` is a decimal fraction of the trade's notional value,
 *   charged on both the buy and the sell (e.g. `'0.001'` = 0.1% per side).
 *   `'0'` (the default) disables it.
 * - `$slippageRate` is a decimal fraction applied to the candle's `close`
 *   price to get the effective execution price: worse than market in both
 *   directions — higher on a BUY, lower on a SELL (e.g. `'0.0005'` = 0.05%
 *   slippage). `'0'` (the default) disables it, reproducing the exact
 *   close-price fills used before costs were modeled.
 *
 * The strategy is asked for a signal once per candle, exactly as before.
 * Those signals are then replayed twice: once applying the configured
 * commission/slippage (the "net" run, which drives every field this class
 * produced before costs existed — capital, trades, win rate, drawdown),
 * and once with zero costs (the "gross" run, used only to compute
 * {@see StrategyEvaluation::$grossProfitLoss} and
 * {@see StrategyEvaluation::$totalCosts}). Replaying recorded signals
 * rather than asking the strategy twice keeps this exact even for
 * path-dependent costs, and never invokes the strategy more than once per
 * candle.
 */
final class StrategyEvaluator
{
    public function __construct(
        private string $commissionRate = '0',
        private string $slippageRate = '0',
    ) {}

    /**
     * @param  Candle[]  $candles  chronologically ordered (oldest first)
     */
    public function evaluate(Strategy $strategy, array $candles, string $initialCapital): StrategyEvaluation
    {
        $signals = [];
        foreach ($candles as $index => $candle) {
            $signals[] = $strategy->generate(array_slice($candles, 0, $index + 1));
        }

        $net = $this->simulate($candles, $signals, $initialCapital, $this->commissionRate, $this->slippageRate);
        $gross = $this->simulate($candles, $signals, $initialCapital, commissionRate: '0', slippageRate: '0');

        return $this->summarize($initialCapital, $net, $gross);
    }

    /**
     * Replays a fixed sequence of signals against a cash/position simulation
     * under a given cost model.
     *
     * @param  Candle[]  $candles
     * @param  Signal[]  $signals  one per candle, same order as $candles
     * @return array{finalCapital: string, tradeProfitLosses: list<string>, maxDrawdownPercentage: string, openPosition: array{quantity: string, entryPrice: string, capitalUsed: string}|null}
     */
    private function simulate(
        array $candles,
        array $signals,
        string $initialCapital,
        string $commissionRate,
        string $slippageRate,
    ): array {
        $cash = $initialCapital;

        /** @var array{quantity: string, entryPrice: string, capitalUsed: string}|null $openPosition */
        $openPosition = null;

        /** @var list<string> $tradeProfitLosses */
        $tradeProfitLosses = [];

        $peakEquity = $initialCapital;
        $maxDrawdownPercentage = '0';

        foreach ($candles as $index => $candle) {
            $signal = $signals[$index];

            if ($signal->type === SignalType::BUY && $openPosition === null) {
                $entryPrice = $this->executionPrice($candle->close, $slippageRate, worseForBuyer: true);
                $quantity = bcdiv(bcmul($cash, bcsub('1', $commissionRate, 18), 18), $entryPrice, 18);

                $openPosition = [
                    'quantity' => $quantity,
                    'entryPrice' => $entryPrice,
                    'capitalUsed' => $cash,
                ];
                $cash = '0';
            } elseif ($signal->type === SignalType::SELL && $openPosition !== null) {
                $exitPrice = $this->executionPrice($candle->close, $slippageRate, worseForBuyer: false);
                $grossProceeds = bcmul($openPosition['quantity'], $exitPrice, 18);
                $netProceeds = bcsub($grossProceeds, bcmul($grossProceeds, $commissionRate, 18), 18);

                $tradeProfitLosses[] = bcsub($netProceeds, $openPosition['capitalUsed'], 18);

                $cash = bcadd($cash, $netProceeds, 18);
                $openPosition = null;
            }

            $equity = $this->equity($cash, $openPosition, $candle->close);

            if (bccomp($equity, $peakEquity, 18) >= 0) {
                $peakEquity = $equity;

                continue;
            }

            $drawdownPercentage = bcmul(
                bcdiv(bcsub($peakEquity, $equity, 18), $peakEquity, 18),
                '100',
                18,
            );

            if (bccomp($drawdownPercentage, $maxDrawdownPercentage, 18) > 0) {
                $maxDrawdownPercentage = $drawdownPercentage;
            }
        }

        $lastClose = $candles === [] ? null : $candles[array_key_last($candles)]->close;
        $finalCapital = $this->equity($cash, $openPosition, $lastClose);

        return [
            'finalCapital' => $finalCapital,
            'tradeProfitLosses' => $tradeProfitLosses,
            'maxDrawdownPercentage' => $maxDrawdownPercentage,
            'openPosition' => $openPosition,
        ];
    }

    /**
     * Applies slippage to a market price to get the effective execution
     * price: higher for a buyer, lower for a seller. A zero rate returns
     * the market price unchanged.
     */
    private function executionPrice(string $marketPrice, string $slippageRate, bool $worseForBuyer): string
    {
        if (bccomp($slippageRate, '0', 18) === 0) {
            return $marketPrice;
        }

        $adjustment = $worseForBuyer
            ? bcadd('1', $slippageRate, 18)
            : bcsub('1', $slippageRate, 18);

        return bcmul($marketPrice, $adjustment, 18);
    }

    /**
     * @param  array{quantity: string, entryPrice: string, capitalUsed: string}|null  $openPosition
     */
    private function equity(string $cash, ?array $openPosition, ?string $markPrice): string
    {
        if ($openPosition === null) {
            return $cash;
        }

        return bcadd($cash, bcmul($openPosition['quantity'], $markPrice, 18), 18);
    }

    /**
     * @param  array{finalCapital: string, tradeProfitLosses: list<string>, maxDrawdownPercentage: string, openPosition: array{quantity: string, entryPrice: string, capitalUsed: string}|null}  $net
     * @param  array{finalCapital: string, tradeProfitLosses: list<string>, maxDrawdownPercentage: string, openPosition: array{quantity: string, entryPrice: string, capitalUsed: string}|null}  $gross
     */
    private function summarize(string $initialCapital, array $net, array $gross): StrategyEvaluation
    {
        $finalCapital = $net['finalCapital'];
        $tradeProfitLosses = $net['tradeProfitLosses'];
        $maxDrawdownPercentage = $net['maxDrawdownPercentage'];
        $openPosition = $net['openPosition'];

        $totalTrades = count($tradeProfitLosses);
        $winningTrades = count(array_filter($tradeProfitLosses, fn (string $pnl): bool => bccomp($pnl, '0', 18) > 0));
        $losingTrades = count(array_filter($tradeProfitLosses, fn (string $pnl): bool => bccomp($pnl, '0', 18) < 0));

        $totalProfit = array_reduce(
            $tradeProfitLosses,
            fn (string $carry, string $pnl): string => bccomp($pnl, '0', 18) > 0 ? bcadd($carry, $pnl, 18) : $carry,
            '0',
        );

        $totalLoss = array_reduce(
            $tradeProfitLosses,
            fn (string $carry, string $pnl): string => bccomp($pnl, '0', 18) < 0 ? bcadd($carry, bcmul($pnl, '-1', 18), 18) : $carry,
            '0',
        );

        $winRate = $totalTrades === 0
            ? '0'
            : bcmul(bcdiv((string) $winningTrades, (string) $totalTrades, 18), '100', 18);

        $profitLoss = bcsub($finalCapital, $initialCapital, 18);

        $profitLossPercentage = bccomp($initialCapital, '0', 18) === 0
            ? '0'
            : bcmul(bcdiv($profitLoss, $initialCapital, 18), '100', 18);

        $grossProfitLoss = bcsub($gross['finalCapital'], $initialCapital, 18);
        $totalCosts = bcsub($grossProfitLoss, $profitLoss, 18);

        $profitFactor = match (true) {
            bccomp($totalLoss, '0', 18) === 0 && bccomp($totalProfit, '0', 18) > 0 => 'INF',
            bccomp($totalLoss, '0', 18) === 0 => '0',
            default => bcdiv($totalProfit, $totalLoss, 18),
        };

        return new StrategyEvaluation(
            initialCapital: $initialCapital,
            finalCapital: $finalCapital,
            profitLoss: $profitLoss,
            profitLossPercentage: $profitLossPercentage,
            totalTrades: $totalTrades,
            winningTrades: $winningTrades,
            losingTrades: $losingTrades,
            winRate: $winRate,
            totalProfit: $totalProfit,
            totalLoss: $totalLoss,
            maxDrawdownPercentage: $maxDrawdownPercentage,
            hasOpenPositionAtEnd: $openPosition !== null,
            openPositionQuantity: $openPosition['quantity'] ?? null,
            openPositionEntryPrice: $openPosition['entryPrice'] ?? null,
            grossProfitLoss: $grossProfitLoss,
            totalCosts: $totalCosts,
            profitFactor: $profitFactor,
        );
    }
}
