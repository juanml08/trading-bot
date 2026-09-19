<?php

namespace Tests\Unit;

use App\MarketData\Candle;
use App\MarketData\Timeframe;
use App\Strategy\EmaCrossoverStrategy;
use App\Strategy\SignalType;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class EmaCrossoverStrategyTest extends TestCase
{
    public function test_returns_hold_when_there_are_not_enough_candles(): void
    {
        $signal = (new EmaCrossoverStrategy)->generate($this->candlesWithCloses(array_fill(0, 13, '100')));

        $this->assertSame(SignalType::HOLD, $signal->type);
        $this->assertStringContainsString('Not enough candles', $signal->reason);
    }

    public function test_returns_buy_when_short_ema_crosses_above_long_ema(): void
    {
        $closes = [...array_fill(0, 13, '100'), '150'];

        $signal = (new EmaCrossoverStrategy)->generate($this->candlesWithCloses($closes));

        $this->assertSame(SignalType::BUY, $signal->type);
        $this->assertStringContainsString('crossed above', $signal->reason);
    }

    public function test_returns_sell_when_short_ema_crosses_below_long_ema(): void
    {
        $closes = [...array_fill(0, 13, '100'), '50'];

        $signal = (new EmaCrossoverStrategy)->generate($this->candlesWithCloses($closes));

        $this->assertSame(SignalType::SELL, $signal->type);
        $this->assertStringContainsString('crossed below', $signal->reason);
    }

    public function test_returns_hold_when_there_is_no_crossover(): void
    {
        $signal = (new EmaCrossoverStrategy)->generate($this->candlesWithCloses(array_fill(0, 14, '100')));

        $this->assertSame(SignalType::HOLD, $signal->type);
        $this->assertStringContainsString('No EMA crossover', $signal->reason);
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
