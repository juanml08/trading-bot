<?php

namespace App\Console\Commands;

use App\MarketData\BinanceMarketDataProvider;
use App\MarketData\Timeframe;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('market:test')]
#[Description('Fetch recent BTCUSDT H1 candles from Binance and print them to the console')]
class MarketTestCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $provider = new BinanceMarketDataProvider(
            baseUrl: config('services.binance.base_url'),
        );

        $to = CarbonImmutable::now();
        $from = $to->subHours(24);

        $candles = $provider->getHistoricalCandles('BTCUSDT', Timeframe::Hour1, $from, $to);

        $this->info(sprintf('Received %d candles for BTCUSDT (%s).', count($candles), Timeframe::Hour1->value));

        foreach (array_slice($candles, 0, 5) as $candle) {
            $this->line(sprintf(
                '[%s] O:%s H:%s L:%s C:%s V:%s',
                $candle->timestamp->toDateTimeString(),
                $candle->open,
                $candle->high,
                $candle->low,
                $candle->close,
                $candle->volume,
            ));
        }

        return self::SUCCESS;
    }
}
