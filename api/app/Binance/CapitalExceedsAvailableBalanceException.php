<?php

namespace App\Binance;

use RuntimeException;

/**
 * Thrown when the capital requested for a Trial run exceeds the USDT
 * balance available in the Binance Demo account.
 */
final class CapitalExceedsAvailableBalanceException extends RuntimeException
{
    public function __construct(string $requestedCapital, string $availableBalance)
    {
        parent::__construct(
            "El capital solicitado ({$requestedCapital}) supera el saldo disponible en Binance Demo ({$availableBalance})."
        );
    }
}
