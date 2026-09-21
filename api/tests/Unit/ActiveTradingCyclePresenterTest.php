<?php

namespace Tests\Unit;

use App\Trading\ActiveTradingCyclePresenter;
use PHPUnit\Framework\TestCase;

/**
 * Covers {@see ActiveTradingCyclePresenter::remainingHuman()}'s formatting
 * rules in isolation, independent of any Eloquent model or database.
 */
class ActiveTradingCyclePresenterTest extends TestCase
{
    public function test_zero_or_negative_seconds_format_as_zero_minutes(): void
    {
        $this->assertSame('0m', ActiveTradingCyclePresenter::remainingHuman(0));
        $this->assertSame('0m', ActiveTradingCyclePresenter::remainingHuman(-100));
    }

    public function test_minutes_only(): void
    {
        $this->assertSame('50m', ActiveTradingCyclePresenter::remainingHuman(50 * 60));
    }

    public function test_whole_hours_with_no_remaining_minutes(): void
    {
        $this->assertSame('3h', ActiveTradingCyclePresenter::remainingHuman(3 * 3600));
    }

    public function test_hours_and_minutes(): void
    {
        $this->assertSame('2h 10m', ActiveTradingCyclePresenter::remainingHuman(2 * 3600 + 10 * 60));
    }
}
