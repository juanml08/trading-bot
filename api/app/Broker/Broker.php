<?php

namespace App\Broker;

use App\Strategy\Signal;

/**
 * Contract for executing an already risk-approved signal against a broker
 * (real or simulated). Implementations must not decide whether a signal
 * should be executed — that belongs to Strategy and RiskManager.
 *
 * @see BrokerExecution
 */
interface Broker
{
    public function execute(
        Signal $signal,
        string $symbol,
        string $currentPrice,
        string $capitalToUse,
    ): BrokerExecution;
}
