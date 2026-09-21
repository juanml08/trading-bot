<?php

namespace Tests\Feature;

use App\Models\RiskSetting;
use App\Risk\RiskManager;
use App\Strategy\Signal;
use App\Strategy\SignalType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Unit\RiskManagerTest;

/**
 * Fase 5: unlike {@see RiskManagerTest} (pure, no DB), these
 * cases need a real persisted {@see RiskSetting} to exercise its casts, so
 * they live as a Feature test against the testing database.
 */
class RiskManagerRiskSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_position_size_is_capped_by_max_risk_per_trade_percentage(): void
    {
        $riskSetting = RiskSetting::factory()->create([
            'max_risk_per_trade' => '1.0000',
            'max_open_trades' => 5,
            'max_capital_per_trade' => '1000.00000000',
        ]);

        $assessment = (new RiskManager)->evaluate($this->signal(SignalType::BUY), '20', '20', $riskSetting);

        $this->assertTrue($assessment->allowed);
        $this->assertSame('0.200000000000000000', $assessment->positionSize);
    }

    public function test_position_size_is_capped_by_max_capital_per_trade(): void
    {
        $riskSetting = RiskSetting::factory()->create([
            'max_risk_per_trade' => '50.0000',
            'max_open_trades' => 5,
            'max_capital_per_trade' => '5.00000000',
        ]);

        $assessment = (new RiskManager)->evaluate($this->signal(SignalType::BUY), '100', '100', $riskSetting);

        $this->assertTrue($assessment->allowed);
        $this->assertSame('5.00000000', $assessment->positionSize);
    }

    public function test_buy_is_rejected_when_open_trades_already_reached_the_configured_limit(): void
    {
        $riskSetting = RiskSetting::factory()->create([
            'max_risk_per_trade' => '1.0000',
            'max_open_trades' => 1,
            'max_capital_per_trade' => '1000.00000000',
        ]);

        $assessment = (new RiskManager)->evaluate($this->signal(SignalType::BUY), '20', '20', $riskSetting, openTradesCount: 1);

        $this->assertFalse($assessment->allowed);
        $this->assertStringContainsString('Existing exposure too high', $assessment->reason);
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
