<?php

namespace App\Binance;

/**
 * Enforces the Trial mode invariant that capital to work with must not
 * exceed the USDT balance available in the Binance Demo account. Has no
 * knowledge of Strategy, backtesting, or the HTTP layer.
 *
 * @see CapitalExceedsAvailableBalanceException
 */
final class TrialCapitalValidator
{
    public function __construct(
        private readonly BinanceAccountClient $client,
    ) {}

    /**
     * @throws CapitalExceedsAvailableBalanceException
     */
    public function assertCapitalIsAvailable(string $capital): void
    {
        $available = $this->client->availableBalance('USDT');

        if (bccomp($capital, $available, 18) > 0) {
            throw new CapitalExceedsAvailableBalanceException($capital, $available);
        }
    }
}
