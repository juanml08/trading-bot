<?php

namespace Database\Seeders;

use App\Actions\Strategy\ActivateStrategyAction;
use App\Models\Strategy;
use App\Strategy\StrategyCatalog;
use App\Strategy\StrategyFactory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Populates the `strategies` table from {@see StrategyCatalog}, so the
 * strategies available for "Buscar estrategia" (manual) and for "Modo
 * Automático" Discover also exist as rows that "Aplicar"/
 * {@see ActivateStrategyAction} can reference by name,
 * and that {@see StrategyFactory} can later reconstruct. Both {@see
 * StrategyCatalog::all()} (manual, 6 strategies) and {@see
 * StrategyCatalog::discoveryCandidates()} (Discover, 200 SMA/EMA parameter
 * variations) are seeded, since a candidate Discover selects and activates
 * must be resolvable by name exactly like a manually-applied one.
 */
class StrategySeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (StrategyCatalog::all() as $name => $strategy) {
            Strategy::query()->updateOrCreate(
                ['name' => $name],
                [
                    'class' => $strategy::class,
                    'parameters' => StrategyCatalog::parametersFor($name),
                    'is_active' => true,
                ],
            );
        }

        foreach (StrategyCatalog::discoveryCandidates() as $name => $strategy) {
            Strategy::query()->updateOrCreate(
                ['name' => $name],
                [
                    'class' => $strategy::class,
                    'parameters' => StrategyCatalog::discoveryParametersFor($name),
                    'is_active' => true,
                ],
            );
        }
    }
}
