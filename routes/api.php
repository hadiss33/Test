<?php

use App\Http\Controllers\RouteSyncController;
use Illuminate\Support\Facades\Route;

Route::get('/routes/sync', [RouteSyncController::class, 'sync']);