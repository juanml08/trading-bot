<?php

namespace App\MarketData;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Discovers currently tradable Binance symbols from the public Spot
 * `exchangeInfo` endpoint (no authentication required, read-only) — the same
 * public API {@see BinanceMarketDataProvider} already uses for candles.
 *
 * @see SymbolUniverseProvider
 */
final class BinanceSymbolUniverseProvider implements SymbolUniverseProvider
{
    public function __construct(
        private readonly string $baseUrl,
    ) {}

    public function activeSymbols(string $quoteAsset): array
    {
        $response = Http::baseUrl($this->baseUrl)->get('/api/v3/exchangeInfo');

        if ($response->failed()) {
            throw new RuntimeException(
                "Binance exchangeInfo request failed: HTTP {$response->status()} - {$response->body()}"
            );
        }

        $quoteAsset = strtoupper($quoteAsset);

        return collect($response->json('symbols', []))
            ->filter(fn (array $symbol): bool => ($symbol['status'] ?? null) === 'TRADING'
                && ($symbol['quoteAsset'] ?? null) === $quoteAsset)
            ->pluck('symbol')
            ->values()
            ->all();
    }
}
