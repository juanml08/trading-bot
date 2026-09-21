<?php

namespace App\MarketData;

use Illuminate\Support\Facades\Http;
use JsonMachine\Exception\PathNotFoundException;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
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
        $response = Http::baseUrl($this->baseUrl)
            ->withOptions(['stream' => true])
            ->get('/api/v3/exchangeInfo');

        if ($response->failed()) {
            throw new RuntimeException(
                "Binance exchangeInfo request failed: HTTP {$response->status()} - {$response->body()}"
            );
        }

        $quoteAsset = strtoupper($quoteAsset);

        // exchangeInfo carries thousands of symbols, each with filters/
        // permissions/orderTypes we never read; fully decoding it (via
        // json_decode, whether into arrays or objects) builds that entire
        // tree in memory and is what exhausts a 128M memory_limit. We only
        // need `symbol`, `status`, and `quoteAsset`, so we pull-parse the
        // `symbols` array one entry at a time straight off the response
        // stream and keep only what matches — memory stays proportional to
        // one symbol at a time, not the whole payload.
        $stream = $response->toPsrResponse()->getBody()->detach();

        $activeSymbols = [];

        try {
            $items = Items::fromStream($stream, [
                'pointer' => '/symbols',
                'decoder' => new ExtJsonDecoder(assoc: true),
            ]);

            foreach ($items as $symbol) {
                if (($symbol['status'] ?? null) === 'TRADING' && ($symbol['quoteAsset'] ?? null) === $quoteAsset) {
                    $activeSymbols[] = $symbol['symbol'];
                }
            }
        } catch (PathNotFoundException) {
            // Binance sent a response without a `symbols` array at all.
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $activeSymbols;
    }
}
