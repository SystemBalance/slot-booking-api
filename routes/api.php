<?php

use App\Http\Controllers\Api\AvailabilityController;
use App\Http\Controllers\Api\HoldController;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'api', 'middleware' => ['api']], function () {
    // Slot availability endpoints
    Route::get('/slots/{slotId}/availability', [AvailabilityController::class, 'show']);

    // Hold management endpoints
    Route::post('/slots/{slotId}/hold', [HoldController::class, 'store']);
    Route::post('/holds/{holdId}/confirm', [HoldController::class, 'confirm']);
    Route::delete('/holds/{holdId}', [HoldController::class, 'destroy']);
});
