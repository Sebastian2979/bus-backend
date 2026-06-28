<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\StopController;
use App\Http\Controllers\Api\RouteController;
use App\Http\Controllers\Api\ShapeController;
use App\Http\Controllers\Api\TripController;

Route::get('/routes', [RouteController::class, 'index']);

Route::get('/stops', [StopController::class, 'index']);

Route::get('/shapes/{shapeId}/stops', [StopController::class, 'shapeStops']);
Route::get('/shapes/{line}', [ShapeController::class, 'show']);

Route::get('/trips/{line}', [TripController::class, 'byLine']);
Route::get('/trips/{tripId}/stop-times', [TripController::class, 'stopTimes']);

Route::get('/departures/{tripId}', [TripController::class, 'getUpcomingTripDepartures']);

