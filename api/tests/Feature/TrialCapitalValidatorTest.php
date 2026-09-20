<?php

namespace Tests\Feature;

use App\Binance\BinanceAccountClient;
use App\Binance\CapitalExceedsAvailableBalanceException;
use App\Binance\TrialCapitalValidator;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class TrialCapitalValidatorTest extends TestCase
{
    public function test_capital_at_or_below_the_available_balance_is_allowed(): void
    {
        Http::fake([
            '*' => Http::response(['balances' => [
                ['asset' => 'USDT', 'free' => '10000.00000000', 'locked' => '0.00000000'],
            ]], 200),
        ]);

        $this->validator()->assertCapitalIsAvailable('10000.00000000');
        $this->validator()->assertCapitalIsAvailable('500');

        $this->expectNotToPerformAssertions();
    }

    public function test_capital_above_the_available_balance_is_rejected(): void
    {
        Http::fake([
            '*' => Http::response(['balances' => [
                ['asset' => 'USDT', 'free' => '10000.00000000', 'locked' => '0.00000000'],
            ]], 200),
        ]);

        $this->expectException(CapitalExceedsAvailableBalanceException::class);

        $this->validator()->assertCapitalIsAvailable('12000');
    }

    public function test_it_uses_the_balance_returned_by_binance_not_a_hardcoded_value(): void
    {
        Http::fake([
            '*' => Http::response(['balances' => [
                ['asset' => 'USDT', 'free' => '42.00000000', 'locked' => '0.00000000'],
            ]], 200),
        ]);

        // 42 is allowed (the fake's balance), but 43 is not, proving the
        // validator reads Binance's response rather than a fixed value.
        $this->validator()->assertCapitalIsAvailable('42.00000000');

        $this->expectException(CapitalExceedsAvailableBalanceException::class);
        $this->validator()->assertCapitalIsAvailable('43');
    }

    public function test_it_treats_a_missing_usdt_balance_as_zero_available(): void
    {
        Http::fake([
            '*' => Http::response(['balances' => [
                ['asset' => 'BTC', 'free' => '1.00000000', 'locked' => '0.00000000'],
            ]], 200),
        ]);

        $this->expectException(CapitalExceedsAvailableBalanceException::class);

        $this->validator()->assertCapitalIsAvailable('1');
    }

    public function test_it_propagates_a_binance_failure_without_exposing_the_secret(): void
    {
        Http::fake([
            '*' => Http::response('Unauthorized', 401),
        ]);

        try {
            $this->validator(apiSecret: 'super-secret-value')->assertCapitalIsAvailable('1');
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertNotInstanceOf(CapitalExceedsAvailableBalanceException::class, $exception);
            $this->assertStringNotContainsString('super-secret-value', $exception->getMessage());
        }
    }

    private function validator(string $apiSecret = 'test-api-secret'): TrialCapitalValidator
    {
        return new TrialCapitalValidator(new BinanceAccountClient(
            baseUrl: 'https://demo-api.binance.com',
            apiKey: 'test-api-key',
            apiSecret: $apiSecret,
        ));
    }
}
