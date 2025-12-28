<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{
    RouteSyncController,
    FlightUpdateController,
    FlightSearchController
};


Route::prefix('routes')->group(function() {
    Route::get('/sync', [RouteSyncController::class, 'sync']);
});

Route::prefix('flights')->group(function() {
    
    Route::post('/update', [FlightUpdateController::class, 'update']);
    
    Route::post('/cleanup', [FlightUpdateController::class, 'cleanup']);
    
    Route::post('/check-missing', [FlightUpdateController::class, 'checkMissing']);
    
    Route::get('/search', [FlightSearchController::class, 'search']);
    
    Route::get('/{id}', [FlightSearchController::class, 'show']);
});