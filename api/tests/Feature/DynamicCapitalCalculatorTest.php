<?php

namespace Tests\Feature;

use App\Binance\BinanceAccountClient;
use App\Binance\DynamicCapitalCalculator;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DynamicCapitalCalculatorTest extends TestCase
{
    public function test_a_small_balance_split_across_the_configured_slots(): void
    {
        config(['trading.active_cycles.max_active' => 20, 'trading.capital.reserve_percent' => 0]);

        $this->assertSame('1.000000000000000000', $this->capitalPerSlot('20.00000000'));
    }

    public function test_a_medium_balance_split_across_the_configured_slots(): void
    {
        config(['trading.active_cycles.max_active' => 20, 'trading.capital.reserve_percent' => 0]);

        $this->assertSame('5.000000000000000000', $this->capitalPerSlot('100.00000000'));
    }

    public function test_a_large_balance_split_across_the_configured_slots(): void
    {
        config(['trading.active_cycles.max_active' => 20, 'trading.capital.reserve_percent' => 0]);

        $this->assertSame('250.000000000000000000', $this->capitalPerSlot('5000.00000000'));
    }

    public function test_a_reserve_percent_is_subtracted_from_the_balance_before_dividing(): void
    {
        config(['trading.active_cycles.max_active' => 20, 'trading.capital.reserve_percent' => 10]);

        // 20 * 0.90 / 20 = 0.90
        $this->assertSame('0.900000000000000000', $this->capitalPerSlot('20.00000000'));
    }

    public function test_capital_per_slot_never_exceeds_the_real_available_balance(): void
    {
        config(['trading.active_cycles.max_active' => 1, 'trading.capital.reserve_percent' => 0]);

        $this->assertSame('20.000000000000000000', $this->capitalPerSlot('20.00000000'));
    }

    public function test_it_returns_zero_when_there_are_no_configured_slots(): void
    {
        config(['trading.active_cycles.max_active' => 0]);

        $this->assertSame('0', $this->capitalPerSlot('5000.00000000'));
    }

    private function capitalPerSlot(string $usdtFreeBalance): string
    {
        Http::fake([
            '*' => Http::response(['balances' => [
                ['asset' => 'USDT', 'free' => $usdtFreeBalance, 'locked' => '0.00000000'],
            ]], 200),
        ]);

        $calculator = new DynamicCapitalCalculator(new BinanceAccountClient(
            baseUrl: 'https://demo-api.binance.com',
            apiKey: 'test-api-key',
            apiSecret: 'test-api-secret',
        ));

        return $calculator->capitalPerSlot();
    }
}
