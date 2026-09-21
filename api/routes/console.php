<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Drives the automatic trading mode. Leaving `php artisan schedule:work`
// running locally is enough to keep the bot working for hours/overnight —
// see App\Console\Commands\ProcessAutomaticTradingCommand.
// withoutOverlapping(): each candle evaluation hits Binance and can outlast
// a one-minute tick; without this, a slow run and the next tick could
// process the same ActiveStrategy concurrently.
Schedule::command('automatic:process')->everyMinute()->withoutOverlapping();

// Drives Modo Automático's strategy search retry — see
// App\Console\Commands\AutomaticStrategySearchCommand. Cheap to run every
// minute: it only does work for accounts that are due for a retry.
// withoutOverlapping(): a search attempt calls the Opportunity Scanner and
// evaluates several candidates against Binance, which can take longer than a
// minute; two overlapping runs for the same account could each read the same
// "available slots" count before either activates a cycle, activating more
// candidates than config('trading.active_cycles.max_active') allows.
Schedule::command('automatic:search')->everyMinute()->withoutOverlapping();

// Drives Fase 3's HOLD timeout — see
// App\Console\Commands\ExpireHoldCyclesCommand. A plain state update per due
// cycle, so everyMinute() keeps expiration close to `expires_at` without
// meaningful cost.
Schedule::command('cycles:expire-hold')->everyMinute()->withoutOverlapping();
