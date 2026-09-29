<?php

namespace App\Binance;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Authenticates against Binance's private Spot Account REST API
 * (SIGNED endpoint) to read account information. Has no knowledge of
 * Market Data, Strategy, Risk, or Execution.
 */
final class BinanceAccountClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $apiSecret,
    ) {}

    /**
     * @return BinanceBalance[]
     */
    public function getBalances(): array
    {
        $query = ['timestamp' => (int) round(microtime(true) * 1000)];
        $query['signature'] = $this->sign($query);

        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders(['X-MBX-APIKEY' => $this->apiKey])
            ->get('/api/v3/account', $query);

        if ($response->failed()) {
            throw new RuntimeException(
                "Binance account request failed: HTTP {$response->status()} - {$response->body()}"
            );
        }

        $balances = $response->json('balances') ?? [];

        return array_map(
            fn (array $balance) => new BinanceBalance(
                asset: $balance['asset'],
                free: $balance['free'],
                locked: $balance['locked'],
            ),
            $balances,
        );
    }

    /**
     * The `free` (available, unlocked) balance of a single asset, or "0" if
     * the account holds none of it.
     */
    public function availableBalance(string $asset): string
    {
        foreach ($this->getBalances() as $balance) {
            if ($balance->asset === $asset) {
                return $balance->free;
            }
        }

        return '0';
    }

    /**
     * @param  array<string, int|string>  $query
     */
    private function sign(array $query): string
    {
        return hash_hmac('sha256', http_build_query($query), $this->apiSecret);
    }
}
