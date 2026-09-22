<?php

namespace Tests\Unit;

use App\MarketData\Candle;
use App\MarketData\Timeframe;
use App\Strategy\MomentumStrategy;
use App\Strategy\SignalType;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class MomentumStrategyTest extends TestCase
{
    public function test_returns_hold_when_there_are_not_enough_candles(): void
    {
        $strategy = new MomentumStrategy;

        $signal = $strategy->generate($this->candlesWithCloses(array_fill(0, 11, '100')));

        $this->assertSame(SignalType::HOLD, $signal->type);
        $this->assertStringContainsString('Not enough candles', $signal->reason);
    }

    public function test_returns_buy_when_momentum_crosses_above_zero(): void
    {
        $strategy = new MomentumStrategy;

        $closes = ['100', '100', '100', '100', '100', '100', '100', '100', '100', '100', '95', '110'];

        $signal = $strategy->generate($this->candlesWithCloses($closes));

        $this->assertSame(SignalType::BUY, $signal->type);
        $this->assertStringContainsString('crossed above zero', $signal->reason);
    }

    public function test_returns_sell_when_momentum_crosses_below_zero(): void
    {
        $strategy = new MomentumStrategy;

        $closes = ['100', '100', '100', '100', '100', '100', '100', '100', '100', '100', '110', '90'];

        $signal = $strategy->generate($this->candlesWithCloses($closes));

        $this->assertSame(SignalType::SELL, $signal->type);
        $this->assertStringContainsString('crossed below zero', $signal->reason);
    }

    public function test_returns_hold_when_momentum_does_not_cross_zero(): void
    {
        $strategy = new MomentumStrategy;

        $signal = $strategy->generate($this->candlesWithCloses(array_fill(0, 12, '100')));

        $this->assertSame(SignalType::HOLD, $signal->type);
        $this->assertStringContainsString('did not cross zero', $signal->reason);
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
