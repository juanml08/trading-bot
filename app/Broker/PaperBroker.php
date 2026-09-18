<?php

namespace App\Broker;

use App\Strategy\Signal;
use App\Strategy\SignalType;
use InvalidArgumentException;

/**
 * First, minimal broker: simulates execution without connecting to any
 * exchange or using real money. It only simulates execution — it does not
 * decide whether a signal should be executed, that belongs to Strategy and
 * RiskManager.
 *
 * When a PaperAccount is provided, executions update its virtual cash and
 * positions. Without one, it falls back to a stateless simulation.
 */
final class PaperBroker implements Broker
{
    public function __construct(private ?PaperAccount $account = null) {}

    public function execute(
        Signal $signal,
        string $symbol,
        string $currentPrice,
        string $capitalToUse,
    ): BrokerExecution {
        if ($signal->type === SignalType::HOLD) {
            return new BrokerExecution(
                executed: false,
                reason: 'Signal is HOLD: no operation to execute.',
            );
        }

        if (bccomp($currentPrice, '0', 18) <= 0) {
            return new BrokerExecution(
                executed: false,
                reason: "Current price ({$currentPrice}) must be greater than zero.",
            );
        }

        if ($this->account === null) {
            $quantity = bcdiv($capitalToUse, $currentPrice, 18);

            return new BrokerExecution(
                executed: true,
                reason: "Simulated {$signal->type->value} of {$symbol}: {$quantity} units at {$currentPrice}.",
                executedPrice: $currentPrice,
                quantity: $quantity,
                capitalUsed: $capitalToUse,
            );
        }

        try {
            return $signal->type === SignalType::BUY
                ? $this->executeBuy($symbol, $currentPrice, $capitalToUse)
                : $this->executeSell($symbol, $currentPrice);
        } catch (InvalidArgumentException $e) {
            return new BrokerExecution(
                executed: false,
                reason: $e->getMessage(),
            );
        }
    }

    private function executeBuy(string $symbol, string $currentPrice, string $capitalToUse): BrokerExecution
    {
        $position = $this->account->buy($symbol, $capitalToUse, $currentPrice);

        return new BrokerExecution(
            executed: true,
            reason: "Simulated BUY of {$symbol}: {$position['quantity']} units at {$currentPrice}.",
            executedPrice: $currentPrice,
            quantity: $position['quantity'],
            capitalUsed: $capitalToUse,
        );
    }

    private function executeSell(string $symbol, string $currentPrice): BrokerExecution
    {
        $quantitySold = $this->account->position($symbol)['quantity'] ?? null;

        $capitalReceived = $this->account->sell($symbol, $currentPrice);

        return new BrokerExecution(
            executed: true,
            reason: "Simulated SELL of {$symbol}: {$quantitySold} units at {$currentPrice}.",
            executedPrice: $currentPrice,
            quantity: $quantitySold,
            capitalUsed: $capitalReceived,
        );
    }
}
