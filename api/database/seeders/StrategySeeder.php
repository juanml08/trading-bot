<?php

namespace Database\Seeders;

use App\Models\Strategy;
use App\Strategy\StrategyCatalog;
use App\Strategy\StrategyFactory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Populates the `strategies` table from {@see StrategyCatalog}, so the
 * strategies available for "Buscar estrategia" also exist as rows that
 * "Aplicar" can reference, and that {@see StrategyFactory}
 * can later reconstruct.
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
    }
}
