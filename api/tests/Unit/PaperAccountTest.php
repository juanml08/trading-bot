<?php

namespace Tests\Unit;

use App\Broker\PaperAccount;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PaperAccountTest extends TestCase
{
    public function test_new_account_makes_the_full_initial_capital_available(): void
    {
        $account = new PaperAccount('20');

        $this->assertSame('20', $account->cash());
        $this->assertSame([], $account->positions());
    }

    public function test_buy_reduces_available_cash_by_the_capital_used(): void
    {
        $account = new PaperAccount('20');

        $account->buy('BTCUSDT', '5', '50');

        $this->assertSame(bcsub('20', '5', 18), $account->cash());
    }

    public function test_buy_calculates_the_purchased_quantity_correctly(): void
    {
        $account = new PaperAccount('20');

        $position = $account->buy('BTCUSDT', '5', '50');

        $this->assertSame(bcdiv('5', '50', 18), $position['quantity']);
    }

    public function test_buy_registers_the_position(): void
    {
        $account = new PaperAccount('20');

        $account->buy('BTCUSDT', '5', '50');

        $position = $account->position('BTCUSDT');

        $this->assertNotNull($position);
        $this->assertSame('BTCUSDT', $position['symbol']);
        $this->assertSame('50', $position['entryPrice']);
        $this->assertSame('5', $position['capitalUsed']);
    }

    public function test_sell_increases_available_cash_by_the_capital_received(): void
    {
        $account = new PaperAccount('20');
        $account->buy('BTCUSDT', '5', '50');

        $account->sell('BTCUSDT', '60');

        $expectedQuantity = bcdiv('5', '50', 18);
        $expectedCapitalReceived = bcmul($expectedQuantity, '60', 18);
        $expectedCashAfterBuy = bcsub('20', '5', 18);

        $this->assertSame(bcadd($expectedCashAfterBuy, $expectedCapitalReceived, 18), $account->cash());
    }

    public function test_sell_closes_the_position(): void
    {
        $account = new PaperAccount('20');
        $account->buy('BTCUSDT', '5', '50');

        $account->sell('BTCUSDT', '60');

        $this->assertNull($account->position('BTCUSDT'));
        $this->assertSame([], $account->positions());
    }

    public function test_buy_fails_when_capital_to_use_exceeds_available_cash(): void
    {
        $account = new PaperAccount('20');

        $this->expectException(InvalidArgumentException::class);

        $account->buy('BTCUSDT', '25', '50');
    }

    public function test_sell_fails_when_there_is_no_open_position_for_the_symbol(): void
    {
        $account = new PaperAccount('20');

        $this->expectException(InvalidArgumentException::class);

        $account->sell('BTCUSDT', '50');
    }

    public function test_calculations_keep_decimal_precision_via_bcmath(): void
    {
        $account = new PaperAccount('20');

        $position = $account->buy('BTCUSDT', '1', '3');

        $this->assertSame(bcdiv('1', '3', 18), $position['quantity']);
        $this->assertNotEquals((string) (1 / 3), $position['quantity']);
    }
}
