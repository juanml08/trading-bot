<?php

namespace App\MarketData;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fetches historical OHLCV candles from Binance's public Spot Market Data
 * REST API (no authentication required, read-only).
 *
 * @see MarketDataProvider
 */
final class BinanceMarketDataProvider implements MarketDataProvider
{
    public function __construct(
        private readonly string $baseUrl,
    ) {}

    /**
     * @return Candle[]
     */
    public function getHistoricalCandles(
        string $symbol,
        Timeframe $timeframe,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $response = Http::baseUrl($this->baseUrl)->get('/api/v3/klines', [
            'symbol' => strtoupper($symbol),
            'interval' => $timeframe->value,
            'startTime' => $from->getTimestampMs(),
            'endTime' => $to->getTimestampMs(),
            'limit' => 1000,
        ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "Binance market data request failed for {$symbol} ({$timeframe->value}): ".
                "HTTP {$response->status()} - {$response->body()}"
            );
        }

        return array_map(
            fn (array $kline) => $this->toCandle($symbol, $timeframe, $kline),
            $response->json(),
        );
    }

    /**
     * @param  array<int, mixed>  $kline
     */
    private function toCandle(string $symbol, Timeframe $timeframe, array $kline): Candle
    {
        return new Candle(
            symbol: strtoupper($symbol),
            timeframe: $timeframe,
            timestamp: CarbonImmutable::createFromTimestampMs($kline[0]),
            open: (string) $kline[1],
            high: (string) $kline[2],
            low: (string) $kline[3],
            close: (string) $kline[4],
            volume: (string) $kline[5],
        );
    }
}
