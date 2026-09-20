<?php

namespace App\Binance;

/**
 * Immutable balance of a single asset in a Binance account.
 */
final readonly class BinanceBalance
{
    public function __construct(
        public string $asset,
        public string $free,
        public string $locked,
    ) {}
}
