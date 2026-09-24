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

    /**
     * Discover's candidate universe is deliberately separate from the fixed
     * manual {@see StrategyCatalog::all()} (see
     * test_it_registers_exactly_six_strategies above): expanding it must
     * never change the manual catalog's size.
     */
    public function test_discovery_candidates_do_not_change_the_manual_catalog(): void
    {
        StrategyCatalog::discoveryCandidates();

        $this->assertCount(6, StrategyCatalog::all());
    }

    public function test_discovery_candidates_total_two_hundred(): void
    {
        $this->assertCount(200, StrategyCatalog::discoveryCandidates());
    }

    public function test_discovery_candidates_are_all_sma_or_ema_crossovers_with_no_duplicate_names(): void
    {
        $candidates = StrategyCatalog::discoveryCandidates();
        $names = array_keys($candidates);

        $this->assertSame($names, array_unique($names));

        foreach ($candidates as $strategy) {
            $this->assertTrue(
                $strategy instanceof SmaCrossoverStrategy || $strategy instanceof EmaCrossoverStrategy,
                'Every discovery candidate must reuse the existing SMA/EMA crossover families.',
            );
        }
    }

    public function test_discovery_parameters_for_round_trip_the_short_and_long_period_from_the_name(): void
    {
        $this->assertSame(['shortPeriod' => 5, 'longPeriod' => 20], StrategyCatalog::discoveryParametersFor('SMA 5/20'));
        $this->assertSame(['shortPeriod' => 12, 'longPeriod' => 150], StrategyCatalog::discoveryParametersFor('EMA 12/150'));
        $this->assertSame([], StrategyCatalog::discoveryParametersFor('unknown'));
    }
}
