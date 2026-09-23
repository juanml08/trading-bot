<?php

namespace Tests\Unit;

use App\MarketData\Candle;
use App\MarketData\MarketDataProvider;
use App\MarketData\SymbolUniverseProvider;
use App\MarketData\Timeframe;
use App\Opportunity\OpportunityCandidate;
use App\Opportunity\OpportunityScanner;
use Carbon\CarbonImmutable;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class OpportunityScannerTest extends TestCase
{
    /**
     * Experimento 2: 15 oportunidades/activos por búsqueda (antes 10).
     */
    public function test_the_default_limit_is_fifteen(): void
    {
        $this->assertSame(15, (int) config('trading.opportunity_scanner.limit'));
    }

    public function test_it_returns_at_most_fifteen_candidates_using_the_default_limit(): void
    {
        $symbols = array_map(fn (int $i): string => "SYM{$i}USDT", range(1, 20));
        $scanner = $this->scanner($symbols);

        $candidates = $scanner->scan();

        $this->assertCount(15, $candidates);
    }

    public function test_it_returns_at_most_the_configured_limit(): void
    {
        $symbols = ['AAAUSDT', 'BBBUSDT', 'CCCUSDT', 'DDDUSDT', 'EEEUSDT', 'FFFUSDT', 'GGGUSDT'];
        $scanner = $this->scanner($symbols);

        $candidates = $scanner->scan(3);

        $this->assertCount(3, $candidates);
    }

    public function test_it_uses_the_configured_limit_when_none_is_given(): void
    {
        config(['trading.opportunity_scanner.limit' => 2]);
        $scanner = $this->scanner(['AAAUSDT', 'BBBUSDT', 'CCCUSDT']);

        $candidates = $scanner->scan();

        $this->assertCount(2, $candidates);
    }

    public function test_it_returns_only_the_available_symbols_without_inventing_candidates(): void
    {
        $scanner = $this->scanner(['AAAUSDT', 'BBBUSDT']);

        $candidates = $scanner->scan(5);

        $this->assertCount(2, $candidates);
        $this->assertSame(['AAAUSDT', 'BBBUSDT'], array_map(fn (OpportunityCandidate $c): string => $c->symbol, $candidates));
    }

    public function test_it_ranks_candidates_by_recent_volume_descending(): void
    {
        $volumesBySymbol = ['LOW' => '10', 'HIGH' => '1000', 'MID' => '100'];
        $scanner = $this->scanner(array_keys($volumesBySymbol), $volumesBySymbol);

        $candidates = $scanner->scan(3);

        $this->assertSame(['HIGH', 'MID', 'LOW'], array_map(fn (OpportunityCandidate $c): string => $c->symbol, $candidates));
    }

    /**
     * The Scanner's job is triage, not signal generation: a candidate only
     * carries the symbol and the liquidity score used to rank it, never a
     * BUY/SELL/HOLD decision or a profitability verdict — that is decided
     * downstream by Backtesting/Discovery/Validation/Selector.
     */
    public function test_a_candidate_carries_no_trading_signal_or_profitability_verdict(): void
    {
        $scanner = $this->scanner(['AAAUSDT']);

        $candidate = $scanner->scan(1)[0];

        $this->assertSame(['symbol', 'recentVolume'], array_keys(get_object_vars($candidate)));
    }

    /**
     * A single symbol's market data request failing (e.g. a Binance timeout)
     * must not abort the whole scan — the other symbols are still ranked and
     * returned, exactly as if the failed symbol simply did not exist.
     */
    public function test_a_symbol_that_fails_to_fetch_market_data_is_skipped_without_aborting_the_scan(): void
    {
        $provider = new OpportunityScannerFailingMarketDataProvider(
            volumesBySymbol: ['GOOD' => '100'],
            failingSymbols: ['BAD'],
        );
        $scanner = new OpportunityScanner(new OpportunityScannerFakeUniverseProvider(['GOOD', 'BAD']), $provider);

        $candidates = $scanner->scan(5);

        $this->assertCount(1, $candidates);
        $this->assertSame('GOOD', $candidates[0]->symbol);
    }

    /**
     * The caller must be told which symbol failed and why, so it can record
     * that as a BotEvent (see RunAutomaticSearchAction) instead of the
     * failure silently disappearing.
     */
    public function test_it_reports_each_failed_symbol_and_its_exception_to_the_given_callback(): void
    {
        $provider = new OpportunityScannerFailingMarketDataProvider(
            volumesBySymbol: ['GOOD' => '100'],
            failingSymbols: ['BAD'],
        );
        $scanner = new OpportunityScanner(new OpportunityScannerFakeUniverseProvider(['GOOD', 'BAD']), $provider);

        $failures = [];
        $scanner->scan(5, onSymbolFailure: function (string $symbol, Throwable $exception) use (&$failures): void {
            $failures[] = [$symbol, $exception->getMessage()];
        });

        $this->assertSame([['BAD', 'connection timed out']], $failures);
    }

    /**
     * @param  string[]  $symbols
     * @param  array<string, string>  $volumesBySymbol
     */
    private function scanner(array $symbols, array $volumesBySymbol = []): OpportunityScanner
    {
        return new OpportunityScanner(
            new OpportunityScannerFakeUniverseProvider($symbols),
            new OpportunityScannerFakeMarketDataProvider($volumesBySymbol),
        );
    }
}

final class OpportunityScannerFakeUniverseProvider implements SymbolUniverseProvider
{
    /**
     * @param  string[]  $symbols
     */
    public function __construct(private readonly array $symbols) {}

    public function activeSymbols(string $quoteAsset): array
    {
        return $this->symbols;
    }
}

final class OpportunityScannerFakeMarketDataProvider implements MarketDataProvider
{
    /**
     * @param  array<string, string>  $volumesBySymbol  defaults every symbol to volume "1" when not given
     */
    public function __construct(private readonly array $volumesBySymbol = []) {}

    public function getHistoricalCandles(string $symbol, Timeframe $timeframe, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $volume = $this->volumesBySymbol[$symbol] ?? '1';

        return [new Candle(
            symbol: $symbol,
            timeframe: $timeframe,
            timestamp: $from,
            open: '1',
            high: '1',
            low: '1',
            close: '1',
            volume: $volume,
        )];
    }
}

/**
 * Simulates some symbols failing (e.g. a Binance timeout) while the rest
 * succeed, to exercise the Scanner's per-symbol resilience.
 */
final class OpportunityScannerFailingMarketDataProvider implements MarketDataProvider
{
    /**
     * @param  array<string, string>  $volumesBySymbol
     * @param  string[]  $failingSymbols
     */
    public function __construct(
        private readonly array $volumesBySymbol,
        private readonly array $failingSymbols,
    ) {}

    public function getHistoricalCandles(string $symbol, Timeframe $timeframe, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (in_array($symbol, $this->failingSymbols, true)) {
            throw new RuntimeException('connection timed out');
        }

        return [new Candle(
            symbol: $symbol,
            timeframe: $timeframe,
            timestamp: $from,
            open: '1',
            high: '1',
            low: '1',
            close: '1',
            volume: $this->volumesBySymbol[$symbol] ?? '1',
        )];
    }
}
