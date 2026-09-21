<?php

namespace App\Console\Commands;

use App\Actions\Strategy\ExpireHoldCyclesAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Scheduled entry point for Fase 3's HOLD timeout (see routes/console.php,
 * registered with `Schedule::command(...)->everyMinute()`): expires every
 * {@see App\Models\ActiveTradingCycle} still in HOLD past its `expires_at`,
 * via {@see ExpireHoldCyclesAction}.
 */
#[Signature('cycles:expire-hold')]
#[Description('Expire every ActiveTradingCycle still in HOLD past its expires_at deadline')]
class ExpireHoldCyclesCommand extends Command
{
    public function handle(ExpireHoldCyclesAction $action): int
    {
        $action();

        return self::SUCCESS;
    }
}
