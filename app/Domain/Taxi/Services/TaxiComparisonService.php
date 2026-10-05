<?php

declare(strict_types=1);

namespace App\Domain\Taxi\Services;

use App\Domain\Location\Contracts\GeocodingServiceInterface;
use App\Domain\Location\Contracts\RouteServiceInterface;
use App\Domain\Location\DTOs\Location;
use App\Domain\Location\DTOs\RouteInformation;
use App\Domain\Location\Exceptions\GeocodingException;
use App\Domain\Location\Exceptions\RouteCalculationException;
use App\Domain\Taxi\Contracts\TaxiProviderInterface;
use App\Domain\Taxi\Contracts\TaxiProviderRegistryInterface;
use App\Domain\Taxi\DTOs\TaxiComparisonResult;
use App\Domain\Taxi\DTOs\TaxiQuote;
use App\Domain\Taxi\DTOs\TripRequest;
use App\Domain\Taxi\Exceptions\TaxiComparisonException;
use App\Domain\Taxi\Models\FareEstimate;
use App\Domain\Taxi\Models\RouteSearch;
use Illuminate\Support\Facades\Schema;
use Psr\Log\LoggerInterface;
use Throwable;

class TaxiComparisonService
{
    public function __construct(
        private readonly GeocodingServiceInterface $geocodingService,
        private readonly RouteServiceInterface $routeService,
        private readonly TaxiProviderRegistryInterface $providerRegistry,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Perform the end-to-end taxi comparison.
     *
     * @throws GeocodingException
     * @throws RouteCalculationException
     * @throws TaxiComparisonException
     */
    public function compare(TripRequest $trip, ?string $clientIp = null): TaxiComparisonResult
    {
        $startTime = microtime(true);

        $this->logger->info('Received taxi fare comparison request', [
            'pickup_query' => $trip->pickupQuery,
            'dropoff_query' => $trip->dropoffQuery,
            'requested_at' => $trip->requestedAt->format('Y-m-d H:i:s'),
        ]);

        // 1. Geocode Pickup & Dropoff locations
        $pickup = $this->geocodeLocation($trip->pickupQuery, 'pickup');
        $dropoff = $this->geocodeLocation($trip->dropoffQuery, 'dropoff', $pickup);

        // 2. Calculate Route Distance and Duration
        $route = $this->calculateRoute($pickup, $dropoff);

        // 3. Obtain estimates across all registered providers with failure isolation
        $quotes = $this->collectProviderQuotes($trip, $route);

        $totalDurationMs = round((microtime(true) - $startTime) * 1000, 2);

        $this->logger->info('Completed taxi comparison', [
            'pickup' => $pickup->formattedAddress,
            'dropoff' => $dropoff->formattedAddress,
            'distance_miles' => $route->distanceMiles,
            'duration_minutes' => $route->durationMinutes,
            'total_quotes' => count($quotes),
            'execution_time_ms' => $totalDurationMs,
        ]);

        // 4. Optionally record search for analytics/auditing (fail-safe)
        $this->recordSearchAudit($trip, $pickup, $dropoff, $route, $quotes, $clientIp);

        return new TaxiComparisonResult(
            pickup: $pickup,
            dropoff: $dropoff,
            route: $route,
            quotes: $quotes,
            executionTimeMs: $totalDurationMs,
        );
    }

    private function geocodeLocation(string $address, string $type, ?Location $referenceLocation = null): Location
    {
        try {
            $proximity = $referenceLocation?->coordinates;
            $referenceCity = $referenceLocation?->city;

            return $this->geocodingService->geocode($address, $proximity, $referenceCity);
        } catch (GeocodingException $e) {
            $this->logger->error(sprintf('Geocoding failure for %s location', $type), [
                'type' => $type,
                'address' => $address,
                'error' => $e->getMessage(),
            ]);

            $userMessage = $e->getMessage() ?: sprintf(
                'Could not confidently resolve the %s location. Please provide a more specific address or postcode.',
                $type
            );

            throw new GeocodingException($address, $userMessage, $e->candidates, $e->getCode(), $e);
        }
    }

    private function calculateRoute(Location $pickup, Location $dropoff): RouteInformation
    {
        try {
            $route = $this->routeService->calculateRoute($pickup, $dropoff);
        } catch (RouteCalculationException $e) {
            $this->logger->error('Route calculation failure between locations', [
                'pickup' => $pickup->formattedAddress,
                'dropoff' => $dropoff->formattedAddress,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $this->validateRouteSanity($pickup, $dropoff, $route);

        return $route;
    }

    /**
     * Sanity validation layer to prevent suspicious or impossible routes from reaching the fare engine.
     *
     * @throws RouteCalculationException
     */
    private function validateRouteSanity(Location $pickup, Location $dropoff, RouteInformation $route): void
    {
        // 1. Same-city / metropolitan context sanity check:
        // Intra-city trips within the same city/region should not exceed realistic bounds (e.g. 50 miles)
        $pickupCity = $pickup->city ? strtolower(trim($pickup->city)) : null;
        $dropoffCity = $dropoff->city ? strtolower(trim($dropoff->city)) : null;

        $isSameCity = ($pickupCity !== null && $dropoffCity !== null && $pickupCity === $dropoffCity);

        // Also check if both queries/locations refer to Greater Manchester
        $manchesterTerms = ['manchester', 'salford', 'stockport', 'bolton', 'bury', 'oldham', 'rochdale', 'wigan', 'trafford'];
        $pickupInManchester = ($pickupCity !== null && in_array($pickupCity, $manchesterTerms, true))
            || str_contains(strtolower($pickup->query), 'manchester')
            || str_contains(strtolower($pickup->formattedAddress), 'manchester');
        $dropoffInManchester = ($dropoffCity !== null && in_array($dropoffCity, $manchesterTerms, true))
            || str_contains(strtolower($dropoff->query), 'manchester')
            || str_contains(strtolower($dropoff->formattedAddress), 'manchester');

        if (($isSameCity || ($pickupInManchester && $dropoffInManchester)) && $route->distanceMiles > 50.0) {
            $this->logger->error('Route sanity failure: distance suspiciously large for same-city journey', [
                'pickup' => $pickup->formattedAddress,
                'dropoff' => $dropoff->formattedAddress,
                'distance_miles' => $route->distanceMiles,
            ]);

            throw new RouteCalculationException(
                sprintf(
                    'Calculated route distance (%.1f miles) is inconsistent with the requested local journey between %s and %s.',
                    $route->distanceMiles,
                    $pickup->city ?? 'origin',
                    $dropoff->city ?? 'destination'
                )
            );
        }

        // 2. Straight-line vs road distance sanity check:
        // Calculate haversine distance between origin and destination coordinates
        $straightLineMiles = $this->calculateHaversineDistanceMiles(
            $pickup->coordinates->latitude,
            $pickup->coordinates->longitude,
            $dropoff->coordinates->latitude,
            $dropoff->coordinates->longitude
        );

        if (($straightLineMiles > 5.0 && $route->distanceMiles > ($straightLineMiles * 5.0)) ||
            ($straightLineMiles < 15.0 && $route->distanceMiles > 75.0)) {
            $this->logger->error('Route sanity failure: road distance inconsistent with spatial coordinates', [
                'straight_line_miles' => round($straightLineMiles, 2),
                'road_distance_miles' => $route->distanceMiles,
            ]);

            throw new RouteCalculationException(
                sprintf(
                    'Calculated route distance (%.1f miles) is inconsistent with straight-line spatial distance (%.1f miles).',
                    $route->distanceMiles,
                    $straightLineMiles
                )
            );
        }
    }

    private function calculateHaversineDistanceMiles(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusMiles = 3958.8;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusMiles * $c;
    }

    /**
     * Executes quote calculation for each provider with full failure isolation.
     * If an individual provider encounters an error, it is recorded as unavailable,
     * and the remaining providers proceed unaffected.
     *
     * @return list<TaxiQuote>
     */
    private function collectProviderQuotes(TripRequest $trip, RouteInformation $route): array
    {
        $quotes = [];
        $providers = $this->providerRegistry->all();

        foreach ($providers as $providerSlug => $provider) {
            if (! $provider->isEnabled()) {
                $this->logger->info('Skipping disabled provider', ['provider' => $providerSlug]);
                $quotes[] = TaxiQuote::unavailable(
                    provider: $provider->getProvider(),
                    displayName: $provider->getDisplayName(),
                    errorMessage: 'Provider is currently disabled.',
                );

                continue;
            }

            $quote = $this->safelyCalculateProviderQuote($provider, $trip, $route);
            $quotes[] = $quote;
        }

        return $quotes;
    }

    private function safelyCalculateProviderQuote(
        TaxiProviderInterface $provider,
        TripRequest $trip,
        RouteInformation $route,
    ): TaxiQuote {
        $providerSlug = $provider->getProvider()->value;
        $providerStart = microtime(true);

        try {
            $quote = $provider->getEstimate($trip, $route);

            $this->logger->debug('Provider estimate successful', [
                'provider' => $providerSlug,
                'duration_ms' => round((microtime(true) - $providerStart) * 1000, 2),
            ]);

            return $quote;
        } catch (Throwable $e) {
            // Fault isolation: Never let a single provider failure crash the whole comparison!
            $this->logger->error('Provider quote calculation failed; isolating error', [
                'provider' => $providerSlug,
                'error_class' => get_class($e),
                'error_message' => $e->getMessage(),
                'duration_ms' => round((microtime(true) - $providerStart) * 1000, 2),
            ]);

            return TaxiQuote::unavailable(
                provider: $provider->getProvider(),
                displayName: $provider->getDisplayName(),
                errorMessage: 'Estimate temporarily unavailable for this provider.',
            );
        }
    }

    /**
     * Asynchronously or safely persist search records for analytics.
     *
     * @param  list<TaxiQuote>  $quotes
     */
    private function recordSearchAudit(
        TripRequest $trip,
        Location $pickup,
        Location $dropoff,
        RouteInformation $route,
        array $quotes,
        ?string $clientIp,
    ): void {
        if (! config('taxiscanner.analytics.enabled', false)) {
            return;
        }

        try {
            if (class_exists(RouteSearch::class) && Schema::hasTable('route_searches')) {
                $search = RouteSearch::create([
                    'pickup_query' => $trip->pickupQuery,
                    'dropoff_query' => $trip->dropoffQuery,
                    'pickup_formatted' => $pickup->formattedAddress,
                    'dropoff_formatted' => $dropoff->formattedAddress,
                    'pickup_latitude' => $pickup->coordinates->latitude,
                    'pickup_longitude' => $pickup->coordinates->longitude,
                    'dropoff_latitude' => $dropoff->coordinates->latitude,
                    'dropoff_longitude' => $dropoff->coordinates->longitude,
                    'distance_miles' => $route->distanceMiles,
                    'duration_minutes' => $route->durationMinutes,
                    'client_ip_hash' => $clientIp ? hash('sha256', $clientIp) : null,
                ]);

                if (Schema::hasTable('fare_estimates')) {
                    foreach ($quotes as $quote) {
                        FareEstimate::create([
                            'route_search_id' => $search->id,
                            'provider_slug' => $quote->providerSlug(),
                            'quote_type' => $quote->quoteType->value,
                            'min_price' => $quote->priceRange?->min,
                            'max_price' => $quote->priceRange?->max,
                            'currency' => $quote->currency,
                            'is_available' => $quote->isAvailable,
                            'error_message' => $quote->errorMessage,
                            'metadata' => $quote->metadata,
                        ]);
                    }
                }
            }
        } catch (Throwable $e) {
            $this->logger->warning('Failed to persist comparison search audit record', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
