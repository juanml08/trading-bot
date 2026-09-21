<?php

namespace Tests\Unit;

use App\Risk\RiskManager;
use App\Strategy\Signal;
use App\Strategy\SignalType;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class RiskManagerTest extends TestCase
{
    public function test_buy_is_allowed_when_capital_is_sufficient(): void
    {
        $assessment = (new RiskManager)->evaluate($this->signal(SignalType::BUY), '20', '5');

        $this->assertTrue($assessment->allowed);
        $this->assertSame('5', $assessment->positionSize);
    }

    public function test_sell_is_allowed_when_capital_is_sufficient(): void
    {
        $assessment = (new RiskManager)->evaluate($this->signal(SignalType::SELL), '20', '5');

        $this->assertTrue($assessment->allowed);
    }

    public function test_hold_is_rejected(): void
    {
        $assessment = (new RiskManager)->evaluate($this->signal(SignalType::HOLD), '20', '5');

        $this->assertFalse($assessment->allowed);
        $this->assertStringContainsString('HOLD', $assessment->reason);
    }

    public function test_zero_available_capital_is_rejected(): void
    {
        $assessment = (new RiskManager)->evaluate($this->signal(SignalType::BUY), '0', '5');

        $this->assertFalse($assessment->allowed);
    }

    public function test_zero_capital_to_use_is_rejected(): void
    {
        $assessment = (new RiskManager)->evaluate($this->signal(SignalType::BUY), '20', '0');

        $this->assertFalse($assessment->allowed);
    }

    public function test_capital_to_use_greater_than_available_is_rejected(): void
    {
        $assessment = (new RiskManager)->evaluate($this->signal(SignalType::BUY), '20', '25');

        $this->assertFalse($assessment->allowed);
    }

    public function test_without_a_risk_setting_the_full_requested_capital_is_used(): void
    {
        $assessment = (new RiskManager)->evaluate($this->signal(SignalType::BUY), '1000', '1000');

        $this->assertTrue($assessment->allowed);
        $this->assertSame('1000', $assessment->positionSize);
    }

    private function signal(SignalType $type): Signal
    {
        return new Signal(
            type: $type,
            reason: 'test signal',
            generatedAt: CarbonImmutable::now(),
        );
    }
}
