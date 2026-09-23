<?php

namespace App\Opportunity;

use App\MarketData\Candle;
use App\MarketData\MarketDataProvider;
use App\MarketData\SymbolUniverseProvider;
use App\MarketData\Timeframe;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Reduces a tradable symbol universe to a small set of candidates worth
 * handing to the Strategy Pipeline for evaluation.
 *
 * This is deliberately NOT a signal generator and NOT a profitability
 * judgement: it does not decide BUY/SELL/HOLD, size capital, manage risk, or
 * declare anything rentable. Its only job is triage — today, ranking by
 * recent traded volume (liquidity), because that is the only signal our
 * current Market Data actually provides (OHLCV candles) without inventing
 * data we don't have. A low-liquidity symbol produces an unreliable
 * backtest regardless of what a strategy says about it, so filtering for
 * liquidity is a data-quality gate, not a trading opinion. Whether any
 * returned candidate is actually worth trading remains entirely the
 * responsibility of Backtesting/Discovery/Validation/Selector.
 *
 * It is also market-agnostic: it depends only on {@see SymbolUniverseProvider}
 * and {@see MarketDataProvider}, so a future stocks/commodities/indices
 * provider can be plugged in without changing this class.
 */
final readonly class OpportunityScanner
{
    public function __construct(
        private SymbolUniverseProvider $universeProvider,
        private MarketDataProvider $marketDataProvider,
    ) {}

    /**
     * @param  (callable(string $symbol, Throwable $exception): void)|null  $onSymbolFailure  called for
     *                                                                                        each symbol whose market data request fails (e.g. a Binance timeout), instead of letting
     *                                                                                        the whole scan fail — see the class docblock's note on per-symbol resilience.
     * @return OpportunityCandidate[] ranked by recent volume, descending;
     *                                at most $limit, fewer if the universe
     *                                does not have enough tradable symbols
     */
    public function scan(?int $limit = null, ?callable $onSymbolFailure = null): array
    {
        $limit ??= (int) config('trading.opportunity_scanner.limit');
        $quoteAsset = (string) config('trading.opportunity_scanner.quote_asset');
        $timeframe = Timeframe::from((string) config('trading.opportunity_scanner.timeframe'));
        $lookbackCandles = (int) config('trading.opportunity_scanner.lookback_candles');
        $maxUniverseSize = (int) config('trading.opportunity_scanner.max_universe_size');

        $symbols = array_slice($this->universeProvider->activeSymbols($quoteAsset), 0, $maxUniverseSize);

        $now = CarbonImmutable::now();
        $from = $now->subMinutes($timeframe->intervalInMinutes() * $lookbackCandles);

        $candidates = [];

        foreach ($symbols as $symbol) {
            // A single symbol's market data request failing (e.g. a Binance
            // timeout) must not abort the scan for every other symbol — it
            // is simply excluded from ranking, exactly like a symbol with no
            // candles is below. $onSymbolFailure lets the caller (see
            // RunAutomaticSearchAction) record which symbol failed and why.
            try {
                $candles = $this->marketDataProvider->getHistoricalCandles($symbol, $timeframe, $from, $now);
            } catch (Throwable $exception) {
                if ($onSymbolFailure !== null) {
                    $onSymbolFailure($symbol, $exception);
                }

                continue;
            }

            if ($candles === []) {
                continue;
            }

            $candidates[] = new OpportunityCandidate($symbol, $this->totalVolume($candles));
        }

        usort(
            $candidates,
            fn (OpportunityCandidate $a, OpportunityCandidate $b): int => bccomp($b->recentVolume, $a->recentVolume, 8),
        );

        return array_slice($candidates, 0, $limit);
    }

    /**
     * @param  Candle[]  $candles
     */
    private function totalVolume(array $candles): string
    {
        return array_reduce(
            $candles,
            fn (string $carry, Candle $candle): string => bcadd($carry, $candle->volume, 8),
            '0',
        );
    }
}
