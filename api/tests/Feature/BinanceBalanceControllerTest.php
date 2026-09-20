<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BinanceBalanceControllerTest extends TestCase
{
    public function test_it_returns_the_account_balances_as_json(): void
    {
        Http::fake([
            '*' => Http::response([
                'balances' => [
                    ['asset' => 'USDT', 'free' => '10000.00000000', 'locked' => '0.00000000'],
                    ['asset' => 'BTC', 'free' => '0.00000000', 'locked' => '0.00000000'],
                ],
            ], 200),
        ]);

        $response = $this->getJson('/api/binance/balance');

        $response->assertOk()->assertExactJson([
            'balances' => [
                ['asset' => 'USDT', 'free' => '10000.00000000', 'locked' => '0.00000000'],
                ['asset' => 'BTC', 'free' => '0.00000000', 'locked' => '0.00000000'],
            ],
        ]);
    }

    public function test_it_returns_a_safe_error_when_binance_fails(): void
    {
        Http::fake([
            '*' => Http::response(['code' => -2015, 'msg' => 'Invalid API-key, IP, or permissions for action.'], 401),
        ]);

        $response = $this->getJson('/api/binance/balance');

        $response->assertStatus(502)->assertExactJson([
            'message' => 'No se pudo consultar la cuenta Binance Demo.',
        ]);
    }

    public function test_it_never_exposes_the_api_secret(): void
    {
        config(['services.binance.api_secret' => 'super-secret-value']);

        Http::fake([
            '*' => Http::response(['code' => -2015, 'msg' => 'error'], 401),
        ]);

        $response = $this->getJson('/api/binance/balance');

        $response->assertDontSee('super-secret-value');
    }
}
