<?php

namespace App\MarketData;

enum Timeframe: string
{
    case Minute1 = '1m';
    case Minute5 = '5m';
    case Minute15 = '15m';
    case Minute30 = '30m';
    case Hour1 = '1h';
    case Hour4 = '4h';
    case Day1 = '1d';
    case Week1 = '1w';
}
