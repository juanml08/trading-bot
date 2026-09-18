<?php

namespace App\Strategy;

enum SignalType: string
{
    case BUY = 'BUY';
    case SELL = 'SELL';
    case HOLD = 'HOLD';
}
