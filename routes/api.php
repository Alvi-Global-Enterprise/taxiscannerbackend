<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\ProviderAdminController;
use App\Http\Controllers\Api\V1\Admin\StatsAdminController;
use App\Http\Controllers\Api\V1\CompareController;
use App\Http\Controllers\Api\V1\TrackClickController;
use App\Http\Middleware\AdminTokenMiddleware;
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

    // Public booking-click tracking (for admin stats)
    Route::post('/track/click', TrackClickController::class)
        ->middleware('throttle:120,1')
        ->name('api.v1.track.click');

    // Admin APIs (Req 2) — require X-Admin-Token / Bearer ADMIN_API_TOKEN
    Route::prefix('admin')
        ->middleware([AdminTokenMiddleware::class, 'throttle:120,1'])
        ->group(function () {
            Route::get('/providers', [ProviderAdminController::class, 'index'])
                ->name('api.v1.admin.providers.index');
            Route::get('/providers/{slug}', [ProviderAdminController::class, 'show'])
                ->name('api.v1.admin.providers.show');
            Route::put('/providers/{slug}/coverage', [ProviderAdminController::class, 'updateCoverage'])
                ->name('api.v1.admin.providers.coverage');
            Route::put('/providers/{slug}/postcodes', [ProviderAdminController::class, 'syncPostcodesOnly'])
                ->name('api.v1.admin.providers.postcodes');
            Route::put('/providers/{slug}/towns', [ProviderAdminController::class, 'syncTownsOnly'])
                ->name('api.v1.admin.providers.towns');
            Route::get('/towns', [ProviderAdminController::class, 'townCatalogue'])
                ->name('api.v1.admin.towns');
            Route::get('/stats', [StatsAdminController::class, 'index'])
                ->name('api.v1.admin.stats');
        });
});

// Alias: /api/compare → same handler as /api/v1/compare
Route::post('/compare', CompareController::class)
    ->middleware('throttle:60,1')
    ->name('api.compare');
