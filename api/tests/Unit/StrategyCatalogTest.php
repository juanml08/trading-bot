<?php

namespace Tests\Unit;

use App\Strategy\BollingerBandsStrategy;
use App\Strategy\EmaCrossoverStrategy;
use App\Strategy\MomentumStrategy;
use App\Strategy\RelativeStrengthIndexStrategy;
use App\Strategy\SimpleMovingAverageStrategy;
use App\Strategy\SmaCrossoverStrategy;
use App\Strategy\Strategy;
use App\Strategy\StrategyCatalog;
use PHPUnit\Framework\TestCase;

class StrategyCatalogTest extends TestCase
{
    public function test_it_registers_exactly_six_strategies(): void
    {
        $this->assertCount(6, StrategyCatalog::all());
    }

    public function test_every_registered_strategy_implements_the_strategy_contract(): void
    {
        foreach (StrategyCatalog::all() as $strategy) {
            $this->assertInstanceOf(Strategy::class, $strategy);
        }
    }

    public function test_each_new_strategy_is_a_distinct_class_from_the_existing_ones(): void
    {
        $classes = array_map(get_class(...), StrategyCatalog::all());

        $this->assertSame($classes, array_unique($classes), 'Strategy classes must not repeat under different names.');

        $this->assertContains(RelativeStrengthIndexStrategy::class, $classes);
        $this->assertContains(BollingerBandsStrategy::class, $classes);
        $this->assertContains(MomentumStrategy::class, $classes);
        $this->assertContains(SimpleMovingAverageStrategy::class, $classes);
        $this->assertContains(SmaCrossoverStrategy::class, $classes);
        $this->assertContains(EmaCrossoverStrategy::class, $classes);
    }

    public function test_parameters_for_new_strategies_are_empty_since_they_take_no_constructor_arguments(): void
    {
        $this->assertSame([], StrategyCatalog::parametersFor('RSI'));
        $this->assertSame([], StrategyCatalog::parametersFor('Bollinger Bands'));
        $this->assertSame([], StrategyCatalog::parametersFor('Momentum'));
    }
}
