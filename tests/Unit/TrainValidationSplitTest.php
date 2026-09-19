<?php

namespace Tests\Unit;

use App\MarketData\Candle;
use App\MarketData\Timeframe;
use App\Strategy\TrainValidationSplit;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TrainValidationSplitTest extends TestCase
{
    public function test_a_70_30_split_puts_the_earliest_candles_in_train_and_the_rest_in_validation(): void
    {
        $candles = $this->candles(100);

        $result = (new TrainValidationSplit(70))->split($candles);

        $this->assertCount(70, $result['train']);
        $this->assertCount(30, $result['validation']);
        $this->assertSame(array_slice($candles, 0, 70), $result['train']);
        $this->assertSame(array_slice($candles, 70), $result['validation']);
    }

    public function test_train_and_validation_do_not_overlap(): void
    {
        $candles = $this->candles(50);

        $result = (new TrainValidationSplit(70))->split($candles);

        $overlap = array_uintersect(
            $result['train'],
            $result['validation'],
            fn (Candle $a, Candle $b): int => spl_object_id($a) <=> spl_object_id($b),
        );

        $this->assertSame([], $overlap);
    }

    public function test_train_and_validation_together_preserve_every_original_candle(): void
    {
        $candles = $this->candles(37);

        $result = (new TrainValidationSplit(70))->split($candles);

        $this->assertSame($candles, [...$result['train'], ...$result['validation']]);
    }

    public function test_each_side_preserves_the_original_chronological_order(): void
    {
        $candles = $this->candles(20);

        $result = (new TrainValidationSplit(60))->split($candles);

        $this->assertSame(array_slice($candles, 0, 12), $result['train']);
        $this->assertSame(array_slice($candles, 12), $result['validation']);
    }

    public function test_every_train_timestamp_is_earlier_than_every_validation_timestamp(): void
    {
        $candles = $this->candles(25);

        $result = (new TrainValidationSplit(70))->split($candles);

        $maxTrainTimestamp = max(array_map(fn (Candle $c): int => $c->timestamp->getTimestamp(), $result['train']));
        $minValidationTimestamp = min(array_map(fn (Candle $c): int => $c->timestamp->getTimestamp(), $result['validation']));

        $this->assertLessThan($minValidationTimestamp, $maxTrainTimestamp);
    }

    public function test_the_train_percentage_is_configurable(): void
    {
        $candles = $this->candles(10);

        $result = (new TrainValidationSplit(80))->split($candles);

        $this->assertCount(8, $result['train']);
        $this->assertCount(2, $result['validation']);
    }

    public function test_an_empty_array_yields_two_empty_arrays(): void
    {
        $result = (new TrainValidationSplit(70))->split([]);

        $this->assertSame([], $result['train']);
        $this->assertSame([], $result['validation']);
    }

    public function test_a_single_candle_is_assigned_entirely_to_train(): void
    {
        $candles = $this->candles(1);

        $result = (new TrainValidationSplit(70))->split($candles);

        $this->assertSame($candles, $result['train']);
        $this->assertSame([], $result['validation']);
    }

    public function test_a_low_percentage_still_keeps_at_least_one_candle_on_each_side_for_small_inputs(): void
    {
        $candles = $this->candles(3);

        $result = (new TrainValidationSplit(1))->split($candles);

        $this->assertCount(1, $result['train']);
        $this->assertCount(2, $result['validation']);
    }

    public function test_a_high_percentage_still_keeps_at_least_one_candle_on_each_side_for_small_inputs(): void
    {
        $candles = $this->candles(3);

        $result = (new TrainValidationSplit(99))->split($candles);

        $this->assertCount(2, $result['train']);
        $this->assertCount(1, $result['validation']);
    }

    public function test_zero_percent_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TrainValidationSplit(0);
    }

    public function test_one_hundred_percent_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TrainValidationSplit(100);
    }

    public function test_a_negative_percentage_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TrainValidationSplit(-10);
    }

    public function test_a_percentage_above_one_hundred_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TrainValidationSplit(150);
    }

    public function test_the_original_candles_are_not_modified(): void
    {
        $candles = $this->candles(5);
        $originalCloses = array_map(fn (Candle $c): string => $c->close, $candles);

        (new TrainValidationSplit(70))->split($candles);

        $this->assertSame($originalCloses, array_map(fn (Candle $c): string => $c->close, $candles));
    }

    /**
     * @return Candle[]
     */
    private function candles(int $count): array
    {
        $base = CarbonImmutable::parse('2024-01-01 00:00:00');

        return array_map(
            fn (int $i): Candle => new Candle(
                symbol: 'BTCUSDT',
                timeframe: Timeframe::Hour1,
                timestamp: $base->addHours($i),
                open: (string) (100 + $i),
                high: (string) (101 + $i),
                low: (string) (99 + $i),
                close: (string) (100 + $i),
                volume: '10',
            ),
            range(0, $count - 1),
        );
    }
}
