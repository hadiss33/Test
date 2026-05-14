
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{
    RouteSyncController,
    FlightUpdateController,
    FlightSearchController,
    FlightRangePriceController,
};

Route::prefix('routes')->group(function() {
    Route::get('/sync', [RouteSyncController::class, 'sync']);
});

Route::prefix('flights')->group(function() {
    Route::post('/update', [FlightUpdateController::class, 'update']);

    Route::post('/cleanup', [FlightUpdateController::class, 'cleanup']);
    
    Route::post('/check-missing', [FlightUpdateController::class, 'checkMissing']);
    
    Route::get('/advanced-search', [FlightSearchController::class, 'getAdvancedFlights']);
    
    Route::get('/price-range', [FlightRangePriceController::class, 'getRangeOfPrices']);

    Route::get('/get-row', [FlightRangePriceController::class, 'getTheRow']);
    
});