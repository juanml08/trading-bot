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
Schedule::command('automatic:process')->everyMinute();
