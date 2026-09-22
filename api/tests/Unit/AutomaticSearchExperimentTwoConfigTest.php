<?php

namespace Tests\Unit;

use App\Strategy\StrategyCatalog;
use Tests\TestCase;

/**
 * Locks in the exact configuration values "Experimento 2" of Modo Automático
 * depends on, so a future config change cannot silently drift the running
 * experiment away from what it is meant to measure. Only the values this
 * experiment intentionally changed (see CLAUDE.md's experiment brief) — not
 * Discovery/Validation/Selector/Risk Manager, which stay untouched on
 * purpose so results are comparable against Experimento 1.
 */
class AutomaticSearchExperimentTwoConfigTest extends TestCase
{
    public function test_opportunity_scanner_evaluates_fifteen_opportunities_per_search(): void
    {
        $this->assertSame(15, (int) config('trading.opportunity_scanner.limit'));
        $this->assertSame(15, (int) config('trading.opportunity_scanner.max_universe_size'));
    }

    public function test_opportunity_scanner_timeframe_is_thirty_minutes(): void
    {
        $this->assertSame('30m', config('trading.opportunity_scanner.timeframe'));
    }

    public function test_automatic_search_retries_every_fifteen_minutes(): void
    {
        $this->assertSame(900, (int) config('trading.automatic_search.retry_seconds'));
    }

    public function test_at_most_five_active_cycles_are_allowed(): void
    {
        $this->assertSame(5, (int) config('trading.active_cycles.max_active'));
    }

    public function test_six_strategies_are_available_to_evaluate(): void
    {
        $this->assertCount(6, StrategyCatalog::all());
        $this->assertSame(
            ['SMA Fast', 'SMA Medium', 'EMA Simple', 'RSI', 'Bollinger Bands', 'Momentum'],
            array_keys(StrategyCatalog::all()),
        );
    }
}
