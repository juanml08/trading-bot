<?php

namespace App\Automation;

use App\Broker\Broker;
use App\Broker\BrokerExecution;
use App\Broker\PaperBroker;
use App\Models\Trade;

/**
 * Computes the simulated fill for a BUY (opening) or SELL (closing) of a
 * position tracked by a persisted {@see Trade}, for the
 * automatic trading cycle.
 *
 * This is deliberately separate from {@see PaperBroker}: that
 * class simulates execution for backtesting a single evaluation run (no
 * durable position state between calls), while this one always closes an
 * *existing* persisted position by its actual quantity rather than
 * recomputing one from capital/price. It reuses {@see BrokerExecution} only
 * as the immutable result shape both cases share; it does not implement
 * {@see Broker} and never touches Strategy, Risk, or
 * persistence itself.
 */
final class SimulatedTradeExecutor
{
    public function buy(string $symbol, string $currentPrice, string $capitalToUse): BrokerExecution
    {
        $quantity = bcdiv($capitalToUse, $currentPrice, 18);

        return new BrokerExecution(
            executed: true,
            reason: "Simulated BUY of {$symbol}: {$quantity} units at {$currentPrice}.",
            executedPrice: $currentPrice,
            quantity: $quantity,
            capitalUsed: $capitalToUse,
        );
    }

    public function sell(string $symbol, string $currentPrice, string $openQuantity): BrokerExecution
    {
        $capitalReceived = bcmul($openQuantity, $currentPrice, 18);

        return new BrokerExecution(
            executed: true,
            reason: "Simulated SELL of {$symbol}: {$openQuantity} units at {$currentPrice}.",
            executedPrice: $currentPrice,
            quantity: $openQuantity,
            capitalUsed: $capitalReceived,
        );
    }
}
