<?php

use App\Http\Controllers\ActivateStrategyController;
use App\Http\Controllers\ActiveTradingCycleController;
use App\Http\Controllers\ActiveTradingCyclesController;
use App\Http\Controllers\AutomaticEventsController;
use App\Http\Controllers\AutomaticModeStatusController;
use App\Http\Controllers\AutomaticTradesController;
use App\Http\Controllers\BinanceBalanceController;
use App\Http\Controllers\ResetActivityController;
use App\Http\Controllers\StartAutomaticModeController;
use App\Http\Controllers\StartAutomaticSearchModeController;
use App\Http\Controllers\StopAllTradingCyclesController;
use App\Http\Controllers\StopAutomaticModeController;
use App\Http\Controllers\StopAutomaticSearchModeController;
use App\Http\Controllers\StopTradingCycleController;
use App\Http\Controllers\StrategySearchController;
use App\Http\Controllers\TradingCyclesSummaryController;
use Illuminate\Support\Facades\Route;

Route::get('/binance/balance', BinanceBalanceController::class);
Route::post('/strategies/search', StrategySearchController::class);
Route::post('/strategies/activate', ActivateStrategyController::class);
Route::post('/automatic/start', StartAutomaticModeController::class);
Route::post('/automatic/stop', StopAutomaticModeController::class);
Route::get('/automatic/status', AutomaticModeStatusController::class);
Route::get('/automatic/events', AutomaticEventsController::class);
Route::delete('/automatic/events', ResetActivityController::class);
Route::get('/automatic/trades', AutomaticTradesController::class);
Route::post('/automatic-search/start', StartAutomaticSearchModeController::class);
Route::post('/automatic-search/stop', StopAutomaticSearchModeController::class);

// Fase 4A: multi-cycle Dashboard. Static segments (`summary`, `stop-all`)
// are registered before the `{cycle}` wildcard routes so they are never
// swallowed by them.
Route::get('/cycles', ActiveTradingCyclesController::class);
Route::get('/cycles/summary', TradingCyclesSummaryController::class);
Route::post('/cycles/stop-all', StopAllTradingCyclesController::class);
Route::get('/cycles/{cycle}', ActiveTradingCycleController::class);
Route::post('/cycles/{cycle}/stop', StopTradingCycleController::class);
