<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\StopController;
use App\Http\Controllers\Api\RouteController;
use App\Http\Controllers\Api\ShapeController;
use App\Http\Controllers\Api\TripController;

Route::get('/stops', [StopController::class, 'index']);
Route::get('/routes', [RouteController::class, 'index']);
Route::get('/shapes/{line}', [ShapeController::class, 'show']);

Route::get('/trips/{line}', [TripController::class, 'byLine']);
Route::get('/trips-grouped', [TripController::class, 'grouped']);