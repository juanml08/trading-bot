<?php

namespace Tests\Unit;

use App\MarketData\Candle;
use App\MarketData\Timeframe;
use App\Strategy\RelativeStrengthIndexStrategy;
use App\Strategy\SignalType;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class RelativeStrengthIndexStrategyTest extends TestCase
{
    public function test_returns_hold_when_there_are_not_enough_candles(): void
    {
        $strategy = new RelativeStrengthIndexStrategy;

        $signal = $strategy->generate($this->candlesWithCloses(array_fill(0, 14, '100')));

        $this->assertSame(SignalType::HOLD, $signal->type);
        $this->assertStringContainsString('Not enough candles', $signal->reason);
    }

    public function test_returns_buy_when_rsi_recovers_above_the_oversold_threshold(): void
    {
        $strategy = new RelativeStrengthIndexStrategy;

        // A steady decline drives RSI to 0 (no gains at all yet), then a
        // sharp rally in the very last candle pushes it back above 30.
        $closes = [...$this->decliningCloses(startingAt: '500', steps: 21), '600'];

        $signal = $strategy->generate($this->candlesWithCloses($closes));

        $this->assertSame(SignalType::BUY, $signal->type);
        $this->assertStringContainsString('crossed back above', $signal->reason);
    }

    public function test_returns_sell_when_rsi_retreats_below_the_overbought_threshold(): void
    {
        $strategy = new RelativeStrengthIndexStrategy;

        // A steady rally drives RSI to 100 (no losses at all yet), then a
        // sharp drop in the very last candle pushes it back below 70.
        $closes = [...$this->risingCloses(startingAt: '50', steps: 21), '90'];

        $signal = $strategy->generate($this->candlesWithCloses($closes));

        $this->assertSame(SignalType::SELL, $signal->type);
        $this->assertStringContainsString('crossed back below', $signal->reason);
    }

    public function test_returns_hold_when_rsi_does_not_cross_a_threshold(): void
    {
        $strategy = new RelativeStrengthIndexStrategy;

        $signal = $strategy->generate($this->candlesWithCloses(array_fill(0, 20, '100')));

        $this->assertSame(SignalType::HOLD, $signal->type);
        $this->assertStringContainsString('did not cross', $signal->reason);
    }

    /**
     * @return string[]
     */
    private function decliningCloses(string $startingAt, int $steps): array
    {
        $closes = [];
        $price = $startingAt;

        for ($i = 0; $i < $steps; $i++) {
            $closes[] = $price;
            $price = bcsub($price, '5', 18);
        }

        return $closes;
    }

    /**
     * @return string[]
     */
    private function risingCloses(string $startingAt, int $steps): array
    {
        $closes = [];
        $price = $startingAt;

        for ($i = 0; $i < $steps; $i++) {
            $closes[] = $price;
            $price = bcadd($price, '5', 18);
        }

        return $closes;
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
