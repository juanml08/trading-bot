<?php

namespace Tests\Unit;

use App\Broker\PaperAccount;
use App\Broker\PaperBroker;
use App\Strategy\Signal;
use App\Strategy\SignalType;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class PaperBrokerTest extends TestCase
{
    public function test_buy_with_valid_capital_calculates_quantity_correctly(): void
    {
        $execution = (new PaperBroker)->execute($this->signal(SignalType::BUY), 'BTCUSDT', '50', '10');

        $this->assertTrue($execution->executed);
        $this->assertSame('50', $execution->executedPrice);
        $this->assertSame('10', $execution->capitalUsed);
        $this->assertSame(bcdiv('10', '50', 18), $execution->quantity);
    }

    public function test_hold_does_not_execute_an_operation(): void
    {
        $execution = (new PaperBroker)->execute($this->signal(SignalType::HOLD), 'BTCUSDT', '50', '10');

        $this->assertFalse($execution->executed);
        $this->assertNull($execution->quantity);
        $this->assertNull($execution->executedPrice);
        $this->assertNull($execution->capitalUsed);
    }

    public function test_sell_returns_a_simulated_execution(): void
    {
        $execution = (new PaperBroker)->execute($this->signal(SignalType::SELL), 'BTCUSDT', '50', '10');

        $this->assertTrue($execution->executed);
        $this->assertSame('50', $execution->executedPrice);
        $this->assertSame('10', $execution->capitalUsed);
        $this->assertStringContainsString('SELL', $execution->reason);
    }

    public function test_financial_calculations_keep_decimal_precision_via_bcmath(): void
    {
        $execution = (new PaperBroker)->execute($this->signal(SignalType::BUY), 'BTCUSDT', '3', '1');

        $this->assertSame(bcdiv('1', '3', 18), $execution->quantity);
        $this->assertNotEquals((string) (1 / 3), $execution->quantity);
    }

    public function test_buy_updates_the_linked_account_cash_and_position(): void
    {
        $account = new PaperAccount('20');
        $broker = new PaperBroker($account);

        $execution = $broker->execute($this->signal(SignalType::BUY), 'BTCUSDT', '50', '5');

        $this->assertTrue($execution->executed);
        $this->assertSame(bcsub('20', '5', 18), $account->cash());
        $this->assertSame(bcdiv('5', '50', 18), $account->position('BTCUSDT')['quantity']);
    }

    public function test_sell_closes_the_position_and_increases_the_linked_account_cash(): void
    {
        $account = new PaperAccount('20');
        $broker = new PaperBroker($account);
        $broker->execute($this->signal(SignalType::BUY), 'BTCUSDT', '50', '5');

        $execution = $broker->execute($this->signal(SignalType::SELL), 'BTCUSDT', '60', '0');

        $expectedCapitalReceived = bcmul(bcdiv('5', '50', 18), '60', 18);
        $expectedCashAfterBuy = bcsub('20', '5', 18);

        $this->assertTrue($execution->executed);
        $this->assertSame($expectedCapitalReceived, $execution->capitalUsed);
        $this->assertSame(bcadd($expectedCashAfterBuy, $expectedCapitalReceived, 18), $account->cash());
        $this->assertNull($account->position('BTCUSDT'));
    }

    public function test_buy_fails_when_it_exceeds_the_linked_account_available_cash(): void
    {
        $account = new PaperAccount('20');
        $broker = new PaperBroker($account);

        $execution = $broker->execute($this->signal(SignalType::BUY), 'BTCUSDT', '50', '25');

        $this->assertFalse($execution->executed);
        $this->assertSame('20', $account->cash());
    }

    public function test_sell_fails_when_the_linked_account_has_no_open_position(): void
    {
        $account = new PaperAccount('20');
        $broker = new PaperBroker($account);

        $execution = $broker->execute($this->signal(SignalType::SELL), 'BTCUSDT', '50', '0');

        $this->assertFalse($execution->executed);
        $this->assertSame('20', $account->cash());
    }

    public function test_hold_does_not_modify_the_linked_account(): void
    {
        $account = new PaperAccount('20');
        $broker = new PaperBroker($account);
        $broker->execute($this->signal(SignalType::BUY), 'BTCUSDT', '50', '5');

        $broker->execute($this->signal(SignalType::HOLD), 'BTCUSDT', '999', '999');

        $this->assertSame(bcsub('20', '5', 18), $account->cash());
        $this->assertSame(bcdiv('5', '50', 18), $account->position('BTCUSDT')['quantity']);
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
