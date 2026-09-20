<?php

namespace App\MarketData;

use App\Console\Commands\ProcessAutomaticTradingCommand;

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

    /**
     * The candle interval expressed in minutes, used to decide when a new
     * candle has closed for this timeframe (see
     * {@see ProcessAutomaticTradingCommand}).
     */
    public function intervalInMinutes(): int
    {
        return match ($this) {
            self::Minute1 => 1,
            self::Minute5 => 5,
            self::Minute15 => 15,
            self::Minute30 => 30,
            self::Hour1 => 60,
            self::Hour4 => 240,
            self::Day1 => 1440,
            self::Week1 => 10080,
        };
    }
}
