<?php

use App\Http\Controllers\ActivateStrategyController;
use App\Http\Controllers\AutomaticEventsController;
use App\Http\Controllers\AutomaticModeStatusController;
use App\Http\Controllers\AutomaticTradesController;
use App\Http\Controllers\BinanceBalanceController;
use App\Http\Controllers\StartAutomaticModeController;
use App\Http\Controllers\StopAutomaticModeController;
use App\Http\Controllers\StrategySearchController;
use Illuminate\Support\Facades\Route;

Route::get('/binance/balance', BinanceBalanceController::class);
Route::post('/strategies/search', StrategySearchController::class);
Route::post('/strategies/activate', ActivateStrategyController::class);
Route::post('/automatic/start', StartAutomaticModeController::class);
Route::post('/automatic/stop', StopAutomaticModeController::class);
Route::get('/automatic/status', AutomaticModeStatusController::class);
Route::get('/automatic/events', AutomaticEventsController::class);
Route::get('/automatic/trades', AutomaticTradesController::class);
