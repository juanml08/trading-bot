<?php

namespace Tests\Unit;

use App\MarketData\Candle;
use App\MarketData\MarketDataProvider;
use App\MarketData\SymbolUniverseProvider;
use App\MarketData\Timeframe;
use App\Opportunity\OpportunityCandidate;
use App\Opportunity\OpportunityScanner;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class OpportunityScannerTest extends TestCase
{
    public function test_the_default_limit_is_ten(): void
    {
        $this->assertSame(10, config('trading.opportunity_scanner.limit'));
    }

    public function test_it_returns_at_most_ten_candidates_using_the_default_limit(): void
    {
        $symbols = array_map(fn (int $i): string => "SYM{$i}USDT", range(1, 15));
        $scanner = $this->scanner($symbols);

        $candidates = $scanner->scan();

        $this->assertCount(10, $candidates);
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
