<?php

namespace App\Broker;

use InvalidArgumentException;

/**
 * In-memory virtual account for paper trading: tracks available cash and
 * open positions. It has no knowledge of strategy, risk, or execution
 * simulation — that belongs to Strategy, RiskManager, and Broker.
 *
 * @phpstan-type Position array{symbol: string, quantity: string, entryPrice: string, capitalUsed: string}
 */
final class PaperAccount
{
    private string $cash;

    /** @var array<string, array{symbol: string, quantity: string, entryPrice: string, capitalUsed: string}> */
    private array $positions = [];

    public function __construct(string $initialCash)
    {
        $this->cash = $initialCash;
    }

    public function cash(): string
    {
        return $this->cash;
    }

    /** @return array<string, array{symbol: string, quantity: string, entryPrice: string, capitalUsed: string}> */
    public function positions(): array
    {
        return $this->positions;
    }

    /** @return array{symbol: string, quantity: string, entryPrice: string, capitalUsed: string}|null */
    public function position(string $symbol): ?array
    {
        return $this->positions[$symbol] ?? null;
    }

    /**
     * @return array{symbol: string, quantity: string, entryPrice: string, capitalUsed: string}
     */
    public function buy(string $symbol, string $capitalToUse, string $currentPrice): array
    {
        if (bccomp($capitalToUse, '0', 18) <= 0) {
            throw new InvalidArgumentException("Capital to use ({$capitalToUse}) must be greater than zero.");
        }

        if (bccomp($currentPrice, '0', 18) <= 0) {
            throw new InvalidArgumentException("Current price ({$currentPrice}) must be greater than zero.");
        }

        if (bccomp($capitalToUse, $this->cash, 18) > 0) {
            throw new InvalidArgumentException("Insufficient cash: available {$this->cash}, requested {$capitalToUse}.");
        }

        $quantity = bcdiv($capitalToUse, $currentPrice, 18);

        $this->cash = bcsub($this->cash, $capitalToUse, 18);

        $existing = $this->positions[$symbol] ?? null;

        if ($existing === null) {
            $this->positions[$symbol] = [
                'symbol' => $symbol,
                'quantity' => $quantity,
                'entryPrice' => $currentPrice,
                'capitalUsed' => $capitalToUse,
            ];
        } else {
            $newQuantity = bcadd($existing['quantity'], $quantity, 18);
            $newCapitalUsed = bcadd($existing['capitalUsed'], $capitalToUse, 18);

            $this->positions[$symbol] = [
                'symbol' => $symbol,
                'quantity' => $newQuantity,
                'entryPrice' => bcdiv($newCapitalUsed, $newQuantity, 18),
                'capitalUsed' => $newCapitalUsed,
            ];
        }

        return $this->positions[$symbol];
    }

    /**
     * Closes the full open position for the given symbol at the current
     * price and returns the capital received.
     */
    public function sell(string $symbol, string $currentPrice): string
    {
        $position = $this->positions[$symbol] ?? null;

        if ($position === null) {
            throw new InvalidArgumentException("No open position for {$symbol}.");
        }

        if (bccomp($currentPrice, '0', 18) <= 0) {
            throw new InvalidArgumentException("Current price ({$currentPrice}) must be greater than zero.");
        }

        $capitalReceived = bcmul($position['quantity'], $currentPrice, 18);

        $this->cash = bcadd($this->cash, $capitalReceived, 18);

        unset($this->positions[$symbol]);

        return $capitalReceived;
    }
}
