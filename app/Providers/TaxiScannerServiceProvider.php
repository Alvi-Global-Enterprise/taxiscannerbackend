<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Location\Contracts\GeocodingServiceInterface;
use App\Domain\Location\Contracts\RouteServiceInterface;
use App\Domain\Location\Services\CachedGeocodingService;
use App\Domain\Location\Services\CachedRouteService;
use App\Domain\Location\Services\MapboxGeocodingService;
use App\Domain\Location\Services\MapboxRouteService;
use App\Domain\Location\Services\SimulatedGeocodingService;
use App\Domain\Location\Services\SimulatedRouteService;
use App\Domain\Pricing\Services\PricingStrategyResolver;
use App\Domain\Pricing\Services\StreetCarsGeographicAdjustmentService;
use App\Domain\Pricing\Strategies\BoltPricingStrategy;
use App\Domain\Pricing\Strategies\StreetCarsPricingStrategy;
use App\Domain\Pricing\Strategies\UberPricingStrategy;
use App\Domain\Pricing\Strategies\VeezuPricingStrategy;
use App\Domain\Taxi\Contracts\EstimateEngineInterface;
use App\Domain\Taxi\Contracts\TaxiProviderRegistryInterface;
use App\Domain\Taxi\Providers\BoltEstimateProvider;
use App\Domain\Taxi\Providers\StreetCarsEstimateProvider;
use App\Domain\Taxi\Providers\TaxiProviderRegistry;
use App\Domain\Taxi\Providers\UberEstimateProvider;
use App\Domain\Taxi\Providers\VeezuEstimateProvider;
use App\Domain\Taxi\Services\EstimateEngine;
use App\Domain\Taxi\Services\TaxiComparisonService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

class TaxiScannerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // 1. Geocoding Service Registration (with Caching Decorator)
        $this->app->singleton(GeocodingServiceInterface::class, function ($app) {
            $provider = (string) config('taxiscanner.geocoding.provider', 'mapbox');
            $logger = $app->make(LoggerInterface::class);
            $allowFallback = (bool) config('taxiscanner.geocoding.allow_simulated_fallback', false);
            $fallbackDriver = $allowFallback ? new SimulatedGeocodingService : null;

            $inner = match ($provider) {
                'simulated' => new SimulatedGeocodingService,
                default => new MapboxGeocodingService(
                    apiKey: config('taxiscanner.geocoding.api_key'),
                    logger: $logger,
                    timeoutSeconds: (int) config('taxiscanner.geocoding.timeout', 5),
                    countryFilter: (string) config('taxiscanner.geocoding.country', 'gb'),
                    fallbackDriver: $fallbackDriver,
                    poiMaxRadiusMiles: (float) config('taxiscanner.geocoding.poi_max_radius_miles', 35.0),
                    poiConfidenceThreshold: (float) config('taxiscanner.geocoding.poi_confidence_threshold', 0.60),
                ),
            };

            $ttl = (int) config('taxiscanner.caching.geocoding_ttl', 1209600);

            return new CachedGeocodingService(
                inner: $inner,
                cache: $app->make(CacheRepository::class),
                logger: $logger,
                ttl: $ttl,
                prefix: (string) config('taxiscanner.caching.prefix', 'taxiscanner').':geo:',
            );
        });

        // 2. Route Service Registration (with Caching Decorator)
        $this->app->singleton(RouteServiceInterface::class, function ($app) {
            $provider = (string) config('taxiscanner.routing.provider', 'mapbox');
            $logger = $app->make(LoggerInterface::class);
            $allowFallback = (bool) config('taxiscanner.routing.allow_simulated_fallback', false);
            $fallbackDriver = $allowFallback ? new SimulatedRouteService : null;

            $inner = match ($provider) {
                'simulated' => new SimulatedRouteService,
                default => new MapboxRouteService(
                    apiKey: config('taxiscanner.routing.api_key'),
                    logger: $logger,
                    timeoutSeconds: (int) config('taxiscanner.routing.timeout', 5),
                    fallbackDriver: $fallbackDriver,
                ),
            };

            $ttl = (int) config('taxiscanner.caching.route_ttl', 604800);

            return new CachedRouteService(
                inner: $inner,
                cache: $app->make(CacheRepository::class),
                logger: $logger,
                ttl: $ttl,
                prefix: (string) config('taxiscanner.caching.prefix', 'taxiscanner').':route:',
            );
        });

        // 3. Pricing Strategy Resolver & Strategies
        $this->app->singleton(StreetCarsGeographicAdjustmentService::class, function () {
            return new StreetCarsGeographicAdjustmentService;
        });

        $this->app->singleton(PricingStrategyResolver::class, function ($app) {
            $strategies = [
                new UberPricingStrategy,
                new BoltPricingStrategy,
                new StreetCarsPricingStrategy($app->make(StreetCarsGeographicAdjustmentService::class)),
                new VeezuPricingStrategy,
            ];

            return new PricingStrategyResolver($strategies);
        });

        // 4. Estimate Engine
        $this->app->singleton(EstimateEngineInterface::class, function ($app) {
            return new EstimateEngine(
                strategyResolver: $app->make(PricingStrategyResolver::class),
                logger: $app->make(LoggerInterface::class),
            );
        });

        // 5. Taxi Providers Registry
        $this->app->singleton(TaxiProviderRegistryInterface::class, function ($app) {
            $engine = $app->make(EstimateEngineInterface::class);

            $registry = new TaxiProviderRegistry;
            $registry->register(new UberEstimateProvider($engine));
            $registry->register(new BoltEstimateProvider($engine));
            $registry->register(new StreetCarsEstimateProvider($engine));
            $registry->register(new VeezuEstimateProvider($engine));

            return $registry;
        });

        // 6. Taxi Comparison Orchestrator
        $this->app->singleton(TaxiComparisonService::class, function ($app) {
            return new TaxiComparisonService(
                geocodingService: $app->make(GeocodingServiceInterface::class),
                routeService: $app->make(RouteServiceInterface::class),
                providerRegistry: $app->make(TaxiProviderRegistryInterface::class),
                logger: $app->make(LoggerInterface::class),
            );
        });

        // 7. Estimate Calibration Service
        $this->app->singleton(EstimateCalibrationService::class, function ($app) {
            return new EstimateCalibrationService(
                logger: $app->make(LoggerInterface::class),
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
