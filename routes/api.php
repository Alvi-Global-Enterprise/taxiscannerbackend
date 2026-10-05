<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\CompareController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - TaxiScanner Backend
|--------------------------------------------------------------------------
|
| Versioned API routes for fare comparison. Designed to serve the frontend
| on Vercel (https://taxiscanner.vercel.app/).
|
*/

Route::prefix('v1')->group(function () {
    // Taxi Fare Comparison Endpoint with rate limiting
    Route::post('/compare', CompareController::class)
        ->middleware('throttle:60,1')
        ->name('api.v1.compare');
});
