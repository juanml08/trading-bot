<?php

namespace App\MarketData;

use App\Opportunity\OpportunityScanner;

/**
 * Contract for discovering which symbols can currently be traded, so that
 * {@see OpportunityScanner} does not need to know how a
 * given market (Binance, and later stocks/commodities/indices) exposes its
 * tradable universe.
 */
interface SymbolUniverseProvider
{
    /**
     * @return string[] symbols quoted in $quoteAsset that are currently
     *                  tradable (e.g. "BTCUSDT", "ETHUSDT" for quote asset "USDT")
     */
    public function activeSymbols(string $quoteAsset): array;
}
