<?php

namespace App\Console\Commands;

use App\Binance\BinanceAccountClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('binance:balance')]
#[Description('Connect to Binance Demo and display the account balances')]
class BinanceBalanceCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $apiKey = config('services.binance.api_key');
        $apiSecret = config('services.binance.api_secret');

        if (empty($apiKey) || empty($apiSecret)) {
            $this->error('BINANCE_API_KEY and BINANCE_API_SECRET must be set in .env.');

            return self::FAILURE;
        }

        $client = new BinanceAccountClient(
            baseUrl: config('services.binance.base_url'),
            apiKey: $apiKey,
            apiSecret: $apiSecret,
        );

        try {
            $balances = $client->getBalances();
        } catch (RuntimeException $exception) {
            $this->error("Binance Demo connection failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info('Binance Demo conectado correctamente.');
        $this->newLine();
        $this->info('Balances:');

        $this->table(
            ['Asset', 'Free', 'Locked'],
            array_map(
                fn ($balance) => [$balance->asset, $balance->free, $balance->locked],
                $balances,
            ),
        );

        return self::SUCCESS;
    }
}
