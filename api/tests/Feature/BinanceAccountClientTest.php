<?php

namespace Tests\Feature;

use App\Binance\BinanceAccountClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class BinanceAccountClientTest extends TestCase
{
    public function test_it_sends_a_signed_authenticated_request(): void
    {
        Http::fake([
            '*' => Http::response(['balances' => []], 200),
        ]);

        $client = new BinanceAccountClient(
            baseUrl: 'https://demo-api.binance.com',
            apiKey: 'test-api-key',
            apiSecret: 'test-api-secret',
        );

        $client->getBalances();

        Http::assertSent(function ($request) {
            $query = [];
            parse_str(parse_url((string) $request->url(), PHP_URL_QUERY), $query);

            return str_starts_with((string) $request->url(), 'https://demo-api.binance.com/api/v3/account?')
                && $request->hasHeader('X-MBX-APIKEY', 'test-api-key')
                && isset($query['timestamp'])
                && isset($query['signature'])
                && $query['signature'] === hash_hmac('sha256', http_build_query([
                    'timestamp' => $query['timestamp'],
                ]), 'test-api-secret');
        });
    }

    public function test_it_transforms_a_valid_response_into_balances(): void
    {
        Http::fake([
            '*' => Http::response([
                'balances' => [
                    ['asset' => 'USDT', 'free' => '10000.00000000', 'locked' => '0.00000000'],
                    ['asset' => 'BTC', 'free' => '0.00000000', 'locked' => '0.00000000'],
                ],
            ], 200),
        ]);

        $client = new BinanceAccountClient(
            baseUrl: 'https://demo-api.binance.com',
            apiKey: 'test-api-key',
            apiSecret: 'test-api-secret',
        );

        $balances = $client->getBalances();

        $this->assertCount(2, $balances);
        $this->assertSame('USDT', $balances[0]->asset);
        $this->assertSame('10000.00000000', $balances[0]->free);
        $this->assertSame('0.00000000', $balances[0]->locked);
        $this->assertSame('BTC', $balances[1]->asset);
    }

    public function test_it_throws_on_an_error_response(): void
    {
        Http::fake([
            '*' => Http::response(['code' => -2015, 'msg' => 'Invalid API-key, IP, or permissions for action.'], 401),
        ]);

        $client = new BinanceAccountClient(
            baseUrl: 'https://demo-api.binance.com',
            apiKey: 'test-api-key',
            apiSecret: 'test-api-secret',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Binance account request failed');

        $client->getBalances();
    }

    public function test_it_never_exposes_the_api_secret(): void
    {
        Http::fake([
            '*' => Http::response(['code' => -2015, 'msg' => 'Invalid API-key, IP, or permissions for action.'], 401),
        ]);

        $client = new BinanceAccountClient(
            baseUrl: 'https://demo-api.binance.com',
            apiKey: 'test-api-key',
            apiSecret: 'super-secret-value',
        );

        try {
            $client->getBalances();
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('super-secret-value', $exception->getMessage());
        }

        Http::assertSent(function ($request) {
            return ! str_contains((string) $request->url(), 'super-secret-value')
                && ! str_contains(json_encode($request->headers()), 'super-secret-value');
        });
    }
}
