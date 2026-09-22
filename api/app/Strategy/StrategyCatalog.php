<?php

namespace App\Strategy;

use Database\Seeders\StrategySeeder;

/**
 * The fixed set of {@see Strategy} implementations "Buscar estrategia"
 * evaluates today, keyed by the display name used throughout the app
 * (HTTP responses, the `strategies` table `name` column, the frontend).
 *
 * This is the single source of truth for that list: {@see StrategySearchController}
 * uses it to run the search, and {@see StrategySeeder} uses
 * it to populate the `strategies` table with the same names, classes, and
 * constructor parameters, so a persisted {@see \App\Models\Strategy} row can
 * later be turned back into one of these exact instances by
 * {@see StrategyFactory}.
 */
final class StrategyCatalog
{
    /**
     * @return array<string, Strategy> keyed by strategy name
     */
    public static function all(): array
    {
        return [
            'SMA Fast' => new SimpleMovingAverageStrategy,
            'SMA Medium' => new SmaCrossoverStrategy(shortPeriod: 10, longPeriod: 20),
            'EMA Simple' => new EmaCrossoverStrategy,
            'RSI' => new RelativeStrengthIndexStrategy,
            'Bollinger Bands' => new BollingerBandsStrategy,
            'Momentum' => new MomentumStrategy,
        ];
    }

    /**
     * @return array<string, mixed> constructor named arguments for $name, as used by {@see all()}
     */
    public static function parametersFor(string $name): array
    {
        return match ($name) {
            'SMA Medium' => ['shortPeriod' => 10, 'longPeriod' => 20],
            default => [],
        };
    }
}
