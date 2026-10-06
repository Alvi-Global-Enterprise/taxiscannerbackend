<?php

use App\Domain\Location\Exceptions\GeocodingException;
use App\Domain\Location\Exceptions\RouteCalculationException;
use App\Domain\Taxi\Exceptions\TaxiComparisonException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Throwable;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API / serverless: always prefer JSON so exception rendering does not
        // depend on the View service (which can be unavailable mid-bootstrap).
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            return true;
        });

        $exceptions->render(function (GeocodingException $e, Request $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                $payload = [
                    'success' => false,
                    'message' => $e->getMessage(),
                    'error_code' => 'GEOCODING_FAILED',
                ];

                if (! empty($e->candidates)) {
                    $payload['candidates'] = $e->candidates;
                }

                return response()->json($payload, 422);
            }
        });

        $exceptions->render(function (RouteCalculationException $e, Request $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'error_code' => 'ROUTE_CALCULATION_FAILED',
                ], 422);
            }
        });

        $exceptions->render(function (TaxiComparisonException $e, Request $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'error_code' => 'COMPARISON_FAILED',
                ], 400);
            }
        });
    })->create();
