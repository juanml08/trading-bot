<?php

namespace Tests\Unit;

use App\Strategy\StrategyCatalog;
use Tests\TestCase;

/**
 * Locks in the exact configuration values "Experimento 3" of Modo Automático
 * depends on (supersedes Experimento 2's config test), so a future config
 * change cannot silently drift the running experiment away from what it is
 * meant to measure. This is an experiment configuration, not a definitive
 * optimization. BUY/SELL/HOLD logic, HOLD timeout, win rate, drawdown and
 * minimum P&L thresholds are intentionally unchanged.
 */
class AutomaticSearchExperimentThreeConfigTest extends TestCase
{
    public function test_opportunity_scanner_evaluates_twenty_assets_per_search(): void
    {
        $this->assertSame(20, (int) config('trading.opportunity_scanner.limit'));
        $this->assertSame(20, (int) config('trading.opportunity_scanner.max_universe_size'));
    }

    public function test_opportunity_scanner_timeframe_is_thirty_minutes(): void
    {
        $this->assertSame('30m', config('trading.opportunity_scanner.timeframe'));
    }

    public function test_lookback_is_twenty_days_so_thirty_minute_candles_fit_binances_1000_candle_limit(): void
    {
        $lookbackDays = (int) config('trading.automatic_search.lookback_days');

        $this->assertSame(20, $lookbackDays);
        $this->assertLessThanOrEqual(1000, $lookbackDays * 48);
    }

    public function test_automatic_search_retries_every_fifteen_minutes(): void
    {
        $this->assertSame(900, (int) config('trading.automatic_search.retry_seconds'));
    }

    public function test_at_most_five_active_cycles_are_allowed_and_hold_timeout_is_four_hours(): void
    {
        $this->assertSame(5, (int) config('trading.active_cycles.max_active'));
        $this->assertSame(4, (int) config('trading.active_cycles.hold_timeout_hours'));
    }

    public function test_minimum_trades_are_five_for_discovery_and_six_for_validation(): void
    {
        $this->assertSame(5, (int) config('trading.discovery.minimum_trades'));
        $this->assertSame(6, (int) config('trading.validation.minimum_trades'));
    }

    public function test_other_discovery_and_validation_thresholds_are_unchanged(): void
    {
        $this->assertSame('30', config('trading.discovery.minimum_win_rate'));
        $this->assertSame('15', config('trading.discovery.maximum_drawdown'));
        $this->assertSame('0', config('trading.discovery.minimum_profit_loss'));
        $this->assertSame('40', config('trading.validation.minimum_win_rate'));
        $this->assertSame('10', config('trading.validation.maximum_drawdown'));
        $this->assertSame('0', config('trading.validation.minimum_profit_loss'));
    }

    public function test_discovery_has_two_hundred_candidates_with_ten_short_periods_and_manual_catalog_is_untouched(): void
    {
        $candidates = StrategyCatalog::discoveryCandidates();

        $this->assertCount(200, $candidates);
        $this->assertCount(200, array_unique(array_keys($candidates)));

        $shortPeriods = array_unique(array_map(
            fn (string $name): int => StrategyCatalog::discoveryParametersFor($name)['shortPeriod'],
            array_keys($candidates),
        ));
        sort($shortPeriods);
        $this->assertSame([3, 4, 5, 6, 7, 8, 9, 10, 12, 15], $shortPeriods);

        $this->assertSame(
            ['SMA Fast', 'SMA Medium', 'EMA Simple', 'RSI', 'Bollinger Bands', 'Momentum'],
            array_keys(StrategyCatalog::all()),
        );
    }
}
