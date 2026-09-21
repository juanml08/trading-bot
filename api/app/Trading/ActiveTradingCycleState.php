<?php

namespace App\Trading;

use App\Models\ActiveTradingCycle;

/**
 * Lifecycle state of an {@see ActiveTradingCycle}. The intended
 * future flow is Hold -> (BUY) -> PositionOpen -> (SELL) -> Closed, or
 * Hold -> (timeout) -> Expired — but this phase only models the state
 * itself; nothing transitions a cycle between these automatically yet.
 */
enum ActiveTradingCycleState: string
{
    case Hold = 'HOLD';
    case PositionOpen = 'POSITION_OPEN';
    case Closed = 'CLOSED';
    case Expired = 'EXPIRED';
}
