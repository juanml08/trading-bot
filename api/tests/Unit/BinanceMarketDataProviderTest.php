<?php

namespace Tests\Unit;

use App\MarketData\BinanceMarketDataProvider;
use App\MarketData\Timeframe;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Experimento 2 requires evaluating only closed candles: Binance includes
 * the currently-forming candle in a `/klines` response whenever its open
 * time falls within the requested window (the common case when `$to` is
 * "now"), so the provider must drop any candle that has not fully closed by
 * `$to` yet.
 */
class BinanceMarketDataProviderTest extends TestCase
{
    public function test_it_drops_the_still_forming_candle_when_to_falls_inside_its_interval(): void
    {
        $timeframe = Timeframe::Minute30;
        $to = CarbonImmutable::parse('2026-01-01 10:15:00');
        $from = $to->subHours(2);

        // Closed candle: opens 09:30, closes 10:00 — fully before $to.
        $closedOpen = CarbonImmutable::parse('2026-01-01 09:30:00');
        // Still-forming candle: opens 10:00, closes 10:30 — after $to.
        $formingOpen = CarbonImmutable::parse('2026-01-01 10:00:00');

        Http::fake([
            '*/api/v3/klines*' => Http::response([
                $this->kline($closedOpen, '100'),
                $this->kline($formingOpen, '999'),
            ]),
        ]);

        $provider = new BinanceMarketDataProvider(baseUrl: 'https://example.test');
        $candles = $provider->getHistoricalCandles('BTCUSDT', $timeframe, $from, $to);

        $this->assertCount(1, $candles);
        $this->assertTrue($candles[0]->timestamp->equalTo($closedOpen));
        $this->assertSame('100', $candles[0]->close);
    }

    public function test_it_keeps_a_candle_whose_close_time_exactly_matches_to(): void
    {
        $timeframe = Timeframe::Minute30;
        $closedOpen = CarbonImmutable::parse('2026-01-01 09:30:00');
        $to = $closedOpen->addMinutes(30);
        $from = $closedOpen->subHour();

        Http::fake([
            '*/api/v3/klines*' => Http::response([
                $this->kline($closedOpen, '100'),
            ]),
        ]);

        $provider = new BinanceMarketDataProvider(baseUrl: 'https://example.test');
        $candles = $provider->getHistoricalCandles('BTCUSDT', $timeframe, $from, $to);

        $this->assertCount(1, $candles);
    }

    /**
     * @return array<int, mixed>
     */
    private function kline(CarbonImmutable $openTime, string $close): array
    {
        return [
            $openTime->getTimestampMs(),
            $close,
            $close,
            $close,
            $close,
            '10',
            $openTime->addMinutes(30)->getTimestampMs(),
            '1000',
            100,
            '5',
            '500',
            '0',
        ];
    }
}
