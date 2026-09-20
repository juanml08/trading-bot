<?php

namespace App\Strategy;

use App\Models\Strategy as StrategyModel;

/**
 * Reconstructs a {@see Strategy} instance from a persisted
 * {@see StrategyModel}: the model only stores the strategy's FQCN and its
 * constructor named arguments (see {@see StrategyCatalog}), so the automatic
 * trading cycle can resolve the exact same strategy the user selected and
 * applied, even after the process restarts.
 */
final class StrategyFactory
{
    public static function fromModel(StrategyModel $model): Strategy
    {
        $class = $model->class;

        return new $class(...$model->parameters);
    }
}
