<?php

namespace Tests\Unit;

use App\MarketData\BinanceSymbolUniverseProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class BinanceSymbolUniverseProviderTest extends TestCase
{
    public function test_it_returns_only_trading_symbols_for_the_requested_quote_asset(): void
    {
        Http::fake([
            '*/api/v3/exchangeInfo*' => Http::response([
                'symbols' => [
                    ['symbol' => 'BTCUSDT', 'status' => 'TRADING', 'quoteAsset' => 'USDT'],
                    ['symbol' => 'ETHBTC', 'status' => 'TRADING', 'quoteAsset' => 'BTC'],
                    ['symbol' => 'ADAUSDT', 'status' => 'BREAK', 'quoteAsset' => 'USDT'],
                ],
            ], 200),
        ]);

        $symbols = $this->provider()->activeSymbols('USDT');

        $this->assertSame(['BTCUSDT'], $symbols);
    }

    /**
     * The provider only needs `symbol`, `status`, and `quoteAsset` to build
     * the universe — it must not choke on (or need) the rest of the fields
     * Binance sends, such as `filters`, `permissions`, and `orderTypes`.
     */
    public function test_it_ignores_fields_it_does_not_need(): void
    {
        Http::fake([
            '*/api/v3/exchangeInfo*' => Http::response([
                'symbols' => [
                    [
                        'symbol' => 'BTCUSDT',
                        'status' => 'TRADING',
                        'quoteAsset' => 'USDT',
                        'baseAsset' => 'BTC',
                        'baseAssetPrecision' => 8,
                        'orderTypes' => ['LIMIT', 'MARKET'],
                        'permissions' => ['SPOT'],
                        'filters' => [
                            ['filterType' => 'PRICE_FILTER', 'minPrice' => '0.01'],
                            ['filterType' => 'LOT_SIZE', 'minQty' => '0.00001'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $symbols = $this->provider()->activeSymbols('USDT');

        $this->assertSame(['BTCUSDT'], $symbols);
    }

    public function test_it_normalizes_the_quote_asset_to_uppercase(): void
    {
        Http::fake([
            '*/api/v3/exchangeInfo*' => Http::response([
                'symbols' => [
                    ['symbol' => 'BTCUSDT', 'status' => 'TRADING', 'quoteAsset' => 'USDT'],
                ],
            ], 200),
        ]);

        $symbols = $this->provider()->activeSymbols('usdt');

        $this->assertSame(['BTCUSDT'], $symbols);
    }

    public function test_it_returns_an_empty_array_when_binance_sends_no_symbols(): void
    {
        Http::fake([
            '*/api/v3/exchangeInfo*' => Http::response(['symbols' => []], 200),
        ]);

        $symbols = $this->provider()->activeSymbols('USDT');

        $this->assertSame([], $symbols);
    }

    public function test_it_returns_an_empty_array_when_binance_omits_the_symbols_key(): void
    {
        Http::fake([
            '*/api/v3/exchangeInfo*' => Http::response([], 200),
        ]);

        $symbols = $this->provider()->activeSymbols('USDT');

        $this->assertSame([], $symbols);
    }

    public function test_it_throws_when_the_request_fails(): void
    {
        Http::fake([
            '*/api/v3/exchangeInfo*' => Http::response('boom', 500),
        ]);

        $this->expectException(RuntimeException::class);

        $this->provider()->activeSymbols('USDT');
    }

    private function provider(): BinanceSymbolUniverseProvider
    {
        return new BinanceSymbolUniverseProvider(baseUrl: 'https://binance.test');
    }
}
