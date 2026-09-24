<?php

namespace App\Strategy;

use App\Actions\Strategy\RunAutomaticSearchAction;
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

    /**
     * The wider universe of candidates "Modo Automático" Discover evaluates
     * each search attempt (see {@see RunAutomaticSearchAction}),
     * as opposed to the small, fixed {@see all()} catalog "Buscar estrategia"
     * (manual) evaluates. Discover can afford to cast a much wider net than a
     * single manual search, so instead of a handful of fixed strategies, this
     * generates every (shortPeriod, longPeriod) combination of the existing
     * {@see SmaCrossoverStrategy} and {@see EmaCrossoverStrategy} families —
     * reusing those two already-tested crossover implementations rather than
     * inventing new strategy logic. Discovery/Validation thresholds
     * (`config('trading.discovery')`/`config('trading.validation')`) are
     * untouched by this — only the candidate universe grows.
     *
     * @return array<string, Strategy> keyed by strategy name, exactly like {@see all()}
     */
    public static function discoveryCandidates(): array
    {
        $candidates = [];

        foreach (self::crossoverPeriodPairs() as [$shortPeriod, $longPeriod]) {
            $candidates["SMA {$shortPeriod}/{$longPeriod}"] = new SmaCrossoverStrategy($shortPeriod, $longPeriod);
            $candidates["EMA {$shortPeriod}/{$longPeriod}"] = new EmaCrossoverStrategy($shortPeriod, $longPeriod);
        }

        return $candidates;
    }

    /**
     * @return array<string, mixed> constructor named arguments for a {@see discoveryCandidates()} name
     */
    public static function discoveryParametersFor(string $name): array
    {
        if (! preg_match('/^(?:SMA|EMA) (\d+)\/(\d+)$/', $name, $matches)) {
            return [];
        }

        return ['shortPeriod' => (int) $matches[1], 'longPeriod' => (int) $matches[2]];
    }

    /**
     * 10 short periods x 10 long periods = 100 valid (short < long) pairs per
     * family (SMA, EMA), for 200 discovery candidates total (Experimento 3;
     * Experimento 2 used 5 short periods for 100). Every long period here is
     * well above the largest short period, so every pair is valid by
     * construction; no filtering or deduplication is needed.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    private static function crossoverPeriodPairs(): array
    {
        $shortPeriods = [3, 4, 5, 6, 7, 8, 9, 10, 12, 15];
        $longPeriods = [20, 25, 30, 40, 50, 60, 80, 100, 120, 150];

        $pairs = [];
        foreach ($shortPeriods as $shortPeriod) {
            foreach ($longPeriods as $longPeriod) {
                $pairs[] = [$shortPeriod, $longPeriod];
            }
        }

        return $pairs;
    }
}
