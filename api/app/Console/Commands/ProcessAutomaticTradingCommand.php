<?php

namespace App\Console\Commands;

use App\Automation\AutomaticTradingCycle;
use App\MarketData\BinanceMarketDataProvider;
use App\MarketData\Timeframe;
use App\Models\ActiveStrategy;
use App\Models\BotEvent;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Scheduled entry point for the automatic trading mode (see
 * routes/console.php, registered with `Schedule::command(...)->everyMinute()`).
 *
 * For every {@see ActiveStrategy} with `status = running`, only does work
 * once a full candle of its timeframe has elapsed since it was last
 * evaluated — it does not hit Binance or run the strategy on every tick, to
 * avoid unnecessary requests. Leaving `php artisan schedule:work` running is
 * enough to keep the bot working for hours without a custom long-running
 * loop.
 *
 * A failure processing one active strategy is logged as a {@see BotEvent}
 * and does not stop the others from being processed.
 */
#[Signature('automatic:process')]
#[Description('Process a new candle for every running active strategy, if one has closed since it was last evaluated')]
class ProcessAutomaticTradingCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AutomaticTradingCycle $cycle): int
    {
        $runningStrategies = ActiveStrategy::query()
            ->where('status', ActiveStrategy::STATUS_RUNNING)
            ->with('strategy')
            ->get();

        foreach ($runningStrategies as $active) {
            if (! $this->isDue($active)) {
                continue;
            }

            try {
                $this->processOne($cycle, $active);
            } catch (Throwable $exception) {
                BotEvent::query()->create([
                    'account_id' => $active->account_id,
                    'event_type' => 'error',
                    'asset' => $active->symbol,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }

    private function isDue(ActiveStrategy $active): bool
    {
        $nextDueAt = $active->nextEvaluationAt();

        return $nextDueAt === null || CarbonImmutable::now()->gte($nextDueAt);
    }

    private function processOne(AutomaticTradingCycle $cycle, ActiveStrategy $active): void
    {
        $timeframe = Timeframe::from($active->timeframe);
        $now = CarbonImmutable::now();

        $provider = new BinanceMarketDataProvider(baseUrl: config('services.binance.base_url'));
        $candles = $provider->getHistoricalCandles(
            $active->symbol,
            $timeframe,
            $now->subMinutes($timeframe->intervalInMinutes() * 200),
            $now,
        );

        if ($candles === []) {
            return;
        }

        $cycle->process($active, $candles);

        $active->update(['last_evaluated_at' => $now]);
    }
}
