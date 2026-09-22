<?php

namespace Tests\Unit;

use App\MarketData\Candle;
use App\MarketData\Timeframe;
use App\Strategy\BollingerBandsStrategy;
use App\Strategy\SignalType;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class BollingerBandsStrategyTest extends TestCase
{
    public function test_returns_hold_when_there_are_not_enough_candles(): void
    {
        $strategy = new BollingerBandsStrategy;

        $signal = $strategy->generate($this->candlesWithCloses(array_fill(0, 20, '100')));

        $this->assertSame(SignalType::HOLD, $signal->type);
        $this->assertStringContainsString('Not enough candles', $signal->reason);
    }

    public function test_returns_buy_when_price_bounces_off_the_lower_band(): void
    {
        $strategy = new BollingerBandsStrategy;

        $closes = [...array_fill(0, 19, '100'), '70', '105'];

        $signal = $strategy->generate($this->candlesWithCloses($closes));

        $this->assertSame(SignalType::BUY, $signal->type);
        $this->assertStringContainsString('lower Bollinger Band', $signal->reason);
    }

    public function test_returns_sell_when_price_is_rejected_at_the_upper_band(): void
    {
        $strategy = new BollingerBandsStrategy;

        $closes = [...array_fill(0, 19, '100'), '130', '95'];

        $signal = $strategy->generate($this->candlesWithCloses($closes));

        $this->assertSame(SignalType::SELL, $signal->type);
        $this->assertStringContainsString('upper Bollinger Band', $signal->reason);
    }

    public function test_returns_hold_when_price_stays_within_the_bands(): void
    {
        $strategy = new BollingerBandsStrategy;

        $signal = $strategy->generate($this->candlesWithCloses(array_fill(0, 21, '100')));

        $this->assertSame(SignalType::HOLD, $signal->type);
        $this->assertStringContainsString('did not bounce', $signal->reason);
    }

    /**
     * @param  string[]  $closes
     * @return Candle[]
     */
    private function candlesWithCloses(array $closes): array
    {
        $timestamp = CarbonImmutable::parse('2026-01-01 00:00:00');

        return array_map(
            fn (string $close, int $index) => new Candle(
                symbol: 'BTCUSDT',
                timeframe: Timeframe::Minute1,
                timestamp: $timestamp->addMinutes($index),
                open: $close,
                high: $close,
                low: $close,
                close: $close,
                volume: '1',
            ),
            $closes,
            array_keys($closes),
        );
    }
}
