<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BinanceBalanceCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.binance.base_url' => 'https://demo-api.binance.com',
            'services.binance.api_key' => 'test-api-key',
            'services.binance.api_secret' => 'test-api-secret',
        ]);
    }

    public function test_it_displays_the_account_balances(): void
    {
        Http::fake([
            '*' => Http::response([
                'balances' => [
                    ['asset' => 'USDT', 'free' => '10000.00000000', 'locked' => '0.00000000'],
                    ['asset' => 'BTC', 'free' => '0.00000000', 'locked' => '0.00000000'],
                ],
            ], 200),
        ]);

        // `expectsOutputToContain` matches each output line against only the
        // first substring expectation that fits it, so overlapping
        // substrings on the same table row (e.g. "USDT" and
        // "10000.00000000") can't be asserted independently. We assert
        // against the full buffered output instead.
        $exitCode = Artisan::call('binance:balance');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Binance Demo conectado correctamente.', $output);
        $this->assertStringContainsString('USDT', $output);
        $this->assertStringContainsString('10000.00000000', $output);
    }

    public function test_it_fails_cleanly_when_credentials_are_missing(): void
    {
        config([
            'services.binance.api_key' => null,
            'services.binance.api_secret' => null,
        ]);

        Http::fake();

        $this->artisan('binance:balance')
            ->assertExitCode(1)
            ->expectsOutputToContain('BINANCE_API_KEY and BINANCE_API_SECRET must be set');

        Http::assertNothingSent();
    }

    public function test_it_reports_a_binance_error_cleanly(): void
    {
        Http::fake([
            '*' => Http::response(['code' => -2015, 'msg' => 'Invalid API-key, IP, or permissions for action.'], 401),
        ]);

        $this->artisan('binance:balance')
            ->assertExitCode(1)
            ->expectsOutputToContain('Binance Demo connection failed');
    }

    public function test_it_never_prints_the_api_secret(): void
    {
        Http::fake([
            '*' => Http::response(['balances' => []], 200),
        ]);

        $this->artisan('binance:balance')
            ->assertExitCode(0)
            ->doesntExpectOutputToContain('test-api-secret');
    }
}
