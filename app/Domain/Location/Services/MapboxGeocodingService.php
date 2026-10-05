<?php

declare(strict_types=1);

namespace App\Domain\Location\Services;

use App\Domain\Location\Contracts\GeocodingServiceInterface;
use App\Domain\Location\DTOs\Coordinates;
use App\Domain\Location\DTOs\Location;
use App\Domain\Location\Exceptions\GeocodingException;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;
use Throwable;

class MapboxGeocodingService implements GeocodingServiceInterface
{
    private readonly AirportLocationResolver $airportResolver;

    private readonly RailwayStationLocationResolver $stationResolver;

    private readonly CityContextMatcher $cityMatcher;

    private readonly PoiDisambiguationService $poiDisambiguator;

    public function __construct(
        private readonly ?string $apiKey,
        private readonly LoggerInterface $logger,
        private readonly int $timeoutSeconds = 5,
        private readonly string $countryFilter = 'gb',
        private readonly ?GeocodingServiceInterface $fallbackDriver = null,
        ?AirportLocationResolver $airportResolver = null,
        ?CityContextMatcher $cityMatcher = null,
        ?PoiDisambiguationService $poiDisambiguator = null,
        ?RailwayStationLocationResolver $stationResolver = null,
        private readonly float $poiMaxRadiusMiles = 35.0,
        private readonly float $poiConfidenceThreshold = 0.60,
    ) {
        $this->airportResolver = $airportResolver ?? new AirportLocationResolver;
        $this->stationResolver = $stationResolver ?? new RailwayStationLocationResolver;
        $this->cityMatcher = $cityMatcher ?? new CityContextMatcher;
        $this->poiDisambiguator = $poiDisambiguator ?? new PoiDisambiguationService($this->cityMatcher, $this->logger);
    }

    public function geocode(string $address, ?Coordinates $proximity = null, ?string $referenceCity = null): Location
    {
        // Strip UI selector noise (e.g. ", Select pickup point", ", Select terminal pickup point")
        $cleanedAddress = preg_replace('/\s*,\s*select\s+.*(pickup|dropoff)\s+point\b/i', '', $address);
        $normalized = trim((string) ($cleanedAddress ?: $address));

        if (empty($normalized)) {
            throw new GeocodingException($address, 'Address query cannot be empty.');
        }

        // If API key is missing, check if an explicit development fallback is permitted
        if (empty($this->apiKey)) {
            if ($this->fallbackDriver !== null) {
                $this->logger->warning('Mapbox API key not configured; using configured development fallback driver.');

                return $this->fallbackDriver->geocode($address, $proximity, $referenceCity);
            }

            $this->logger->error('Mapbox geocoding failed: TAXISCANNER_MAP_API_KEY is not configured.');
            throw new GeocodingException($address, 'Geocoding service is unavailable. Please check system configuration.');
        }

        $isAirportQuery = $this->airportResolver->isAirportQuery($normalized);
        $isStationQuery = $this->stationResolver->isStationQuery($normalized);
        $expectedCity = $this->cityMatcher->detectCityContext($normalized);
        $isGenericPoi = $this->poiDisambiguator->isGenericPoiQuery($normalized);

        // 1. Initial query execution against Mapbox
        $features = $this->queryMapbox($normalized, $proximity);

        // 2. Airport handling:
        // Prefer airport/place or POI result over arbitrary residential road.
        if ($isAirportQuery) {
            $airportFeature = $this->selectAirportFeature($features, $normalized);
            if ($airportFeature !== null) {
                return $this->buildLocationFromFeature($address, $airportFeature);
            }

            // If Mapbox returned only arbitrary roads (e.g. "Eastern Link Road") or no POI/place,
            // check the extensible AirportLocationResolver
            $knownAirport = $this->airportResolver->resolve($normalized);
            if ($knownAirport !== null) {
                return $knownAirport;
            }
        }

        // 2b. Railway Station handling:
        // Prioritize actual railway station POI or canonical station over generic street/address result (e.g. Marple Station Road).
        if ($isStationQuery) {
            $stationFeature = $this->selectStationFeature($features, $normalized);
            if ($stationFeature !== null) {
                return $this->buildLocationFromFeature($address, $stationFeature);
            }

            $knownStation = $this->stationResolver->resolve($normalized);
            if ($knownStation !== null) {
                return $knownStation;
            }
        }

        // 3. City context validation & ambiguity handling
        if ($expectedCity !== null) {
            $primaryPart = str_contains($normalized, ',') ? trim(explode(',', $normalized)[0]) : $normalized;

            // Check if initial features contain a specific match for the primary query term
            $hasSpecificPrimaryMatch = false;
            foreach ($features as $f) {
                if (stripos((string) ($f['place_name'] ?? ''), $primaryPart) !== false) {
                    $hasSpecificPrimaryMatch = true;
                    break;
                }
            }

            // If initial features contain a specific match for the query, select it
            if ($hasSpecificPrimaryMatch) {
                $matchingFeature = $this->findBestMatchingCityFeature($features, $expectedCity, $proximity);
                if ($matchingFeature !== null) {
                    return $this->buildLocationFromFeature($address, $matchingFeature);
                }
            }

            // Otherwise, attempt better-constrained refined queries (e.g. "Salford Quays" or "The Trafford Centre")
            $refinedCandidates = $this->cityMatcher->generateRefinedQueries($normalized, $expectedCity);

            foreach ($refinedCandidates as $candidate) {
                $this->logger->info('Attempting refined Mapbox geocoding query', [
                    'original' => $normalized,
                    'refined' => $candidate,
                    'expected_city' => $expectedCity,
                ]);

                try {
                    $candidateFeatures = $this->queryMapbox($candidate, $proximity);
                } catch (Throwable) {
                    continue;
                }

                $refinedMatch = $this->findBestMatchingCityFeature($candidateFeatures, $expectedCity, $proximity);

                if ($refinedMatch !== null) {
                    return $this->buildLocationFromFeature($address, $refinedMatch);
                }
            }

            // If refined queries did not match, check if any initial feature matched the city context
            $matchingFeature = $this->findBestMatchingCityFeature($features, $expectedCity, $proximity);
            if ($matchingFeature !== null) {
                return $this->buildLocationFromFeature($address, $matchingFeature);
            }

            // If we have an expected city and Mapbox results were in a completely different city,
            // do not silently accept the wrong city!
            $firstFeatureCity = ! empty($features) ? $this->extractCityFromFeature($features[0]) : null;
            if ($firstFeatureCity !== null && strcasecmp($firstFeatureCity, $expectedCity) !== 0) {
                $this->logger->warning('Geocoding result rejected: wrong city match', [
                    'query' => $normalized,
                    'expected_city' => $expectedCity,
                    'returned_city' => $firstFeatureCity,
                ]);

                throw new GeocodingException(
                    $address,
                    sprintf('Could not confidently resolve the location in %s. Please provide a more specific address or postcode.', $expectedCity)
                );
            }
        }

        // 4. If Mapbox returned no candidates at all
        if (empty($features)) {
            throw new GeocodingException($address, sprintf('No location found for "%s".', $address));
        }

        // 5. Generic POI / Business Disambiguation (Requirements 1, 2, 3, 4, 8, 11)
        if ($isGenericPoi) {
            $eval = $this->poiDisambiguator->evaluateCandidates(
                rawQuery: $normalized,
                features: $features,
                proximity: $proximity,
                referenceCity: $referenceCity,
                maxPoiRadiusMiles: $this->poiMaxRadiusMiles,
                confidenceThreshold: $this->poiConfidenceThreshold,
            );

            // Telemetry & Debug Logging (Requirement 11)
            $this->logger->info('POI candidate disambiguation evaluation', $eval['debug']);

            if ($eval['status'] === 'SELECTED' && $eval['selected_feature'] !== null) {
                return $this->buildLocationFromFeature($address, $eval['selected_feature']);
            }

            if ($eval['status'] === 'AMBIGUOUS_NO_CANDIDATE') {
                $this->logger->warning('Rejected ambiguous POI with no viable candidate within radius', [
                    'query' => $normalized,
                    'features_count' => count($features),
                ]);

                throw new GeocodingException(
                    addressQuery: $address,
                    message: sprintf(
                        'The destination "%s" is ambiguous with no confident branch found near your pickup. Please specify a more specific address, city, or branch (e.g. "%s, [location]").',
                        $address,
                        $address
                    ),
                    candidates: $eval['candidates'],
                );
            }

            if ($eval['status'] === 'AMBIGUOUS_MULTIPLE_CANDIDATES') {
                $this->logger->info('Ambiguous POI with multiple candidate branches in area', [
                    'query' => $normalized,
                    'candidate_count' => count($eval['candidates']),
                ]);

                throw new GeocodingException(
                    addressQuery: $address,
                    message: sprintf(
                        'The destination "%s" is ambiguous. Multiple locations exist in this area. Please specify your intended branch or address.',
                        $address
                    ),
                    candidates: $eval['candidates'],
                );
            }
        }

        // 5. Default / Explicit Address: If features exist, pick the best feature (first feature)
        if (! empty($features)) {
            return $this->buildLocationFromFeature($address, $features[0]);
        }

        throw new GeocodingException($address, sprintf('No location found for "%s".', $address));
    }

    /**
     * Query Mapbox places API with UK country filter, proximity bias, and expanded feature types.
     *
     * @return list<array<string, mixed>>
     */
    private function queryMapbox(string $query, ?Coordinates $proximity = null): array
    {
        $endpoint = sprintf(
            'https://api.mapbox.com/geocoding/v5/mapbox.places/%s.json',
            rawurlencode($query)
        );

        $params = [
            'access_token' => $this->apiKey,
            'country' => $this->countryFilter,
            'limit' => 10,
            'types' => 'address,poi,postcode,place,locality,neighborhood',
            'autocomplete' => 'false',
        ];

        if ($proximity !== null) {
            $params['proximity'] = sprintf('%.5f,%.5f', $proximity->longitude, $proximity->latitude);
        }

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->acceptJson()
                ->get($endpoint, $params);
        } catch (Throwable $e) {
            $this->logger->error('Mapbox geocoding HTTP request exception', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            if ($this->fallbackDriver !== null) {
                $fallback = $this->fallbackDriver->geocode($query, $proximity);

                return [$this->locationToFeature($fallback)];
            }

            throw new GeocodingException($query, 'Geocoding service temporarily unavailable. Please try again.');
        }

        if (! $response->successful()) {
            $this->logger->error('Mapbox geocoding returned error response', [
                'status' => $response->status(),
                'query' => $query,
            ]);

            if ($this->fallbackDriver !== null) {
                $fallback = $this->fallbackDriver->geocode($query, $proximity);

                return [$this->locationToFeature($fallback)];
            }

            if ($response->status() === 429) {
                throw new GeocodingException($query, 'Geocoding service rate limit reached. Please try again shortly.');
            }

            throw new GeocodingException($query, 'Unable to locate the specified address.');
        }

        $payload = $response->json();

        return $payload['features'] ?? [];
    }

    /**
     * Prefer airport place or POI terminal features over arbitrary residential roads.
     *
     * @param  list<array<string, mixed>>  $features
     * @return array<string, mixed>|null
     */
    private function selectAirportFeature(array $features, string $query): ?array
    {
        // 1. Look for place or poi features matching the airport query
        foreach ($features as $feature) {
            $id = (string) ($feature['id'] ?? '');
            $placeName = strtolower((string) ($feature['place_name'] ?? ''));
            $text = strtolower((string) ($feature['text'] ?? ''));

            // Check if feature is place or poi representing the airport
            if (str_starts_with($id, 'place') || str_starts_with($id, 'poi')) {
                if (str_contains($placeName, 'airport') || str_contains($text, 'airport')) {
                    return $feature;
                }
            }
        }

        // 2. If all features are addresses, check if any feature specifically contains terminal/airport in text
        foreach ($features as $feature) {
            $text = strtolower((string) ($feature['text'] ?? ''));
            if ($text === 'manchester airport' || $text === 'heathrow airport' || $text === 'gatwick airport') {
                return $feature;
            }
        }

        return null;
    }

    /**
     * Prioritize actual railway station POI or transit feature over generic residential street addresses.
     *
     * @param  list<array<string, mixed>>  $features
     * @return array<string, mixed>|null
     */
    private function selectStationFeature(array $features, string $query): ?array
    {
        // 1. Look for explicit POI or transit features representing the station
        foreach ($features as $feature) {
            $id = (string) ($feature['id'] ?? '');
            $placeName = strtolower((string) ($feature['place_name'] ?? ''));
            $text = strtolower((string) ($feature['text'] ?? ''));
            $properties = $feature['properties'] ?? [];
            $category = strtolower((string) ($properties['category'] ?? ''));
            $maki = strtolower((string) ($properties['maki'] ?? ''));

            // Check if feature is a POI
            if (str_starts_with($id, 'poi')) {
                // Confirm it relates to rail/station/transit
                if (str_contains($category, 'station')
                    || str_contains($category, 'rail')
                    || str_contains($category, 'train')
                    || str_contains($maki, 'rail')
                    || str_contains($placeName, 'railway station')
                    || str_contains($placeName, 'train station')
                    || str_contains($text, 'station')) {
                    return $feature;
                }
            }
        }

        // 2. Check for place/locality/neighborhood with explicit "station" text/name
        foreach ($features as $feature) {
            $id = (string) ($feature['id'] ?? '');
            $placeName = strtolower((string) ($feature['place_name'] ?? ''));

            // Reject generic road address features (address.*) like "Station Road, Marple"
            if (str_starts_with($id, 'address')) {
                continue;
            }

            if (str_contains($placeName, 'railway station') || str_contains($placeName, 'train station')) {
                return $feature;
            }
        }

        return null;
    }

    /**
     * Find a feature in the candidate list that matches the expected city context.
     *
     * @param  list<array<string, mixed>>  $features
     * @return array<string, mixed>|null
     */
    private function findBestMatchingCityFeature(array $features, string $expectedCity, ?Coordinates $proximity = null): ?array
    {
        foreach ($features as $feature) {
            if (! $this->cityMatcher->matchesCityContext($feature, $expectedCity)) {
                continue;
            }

            // Proximity sanity guard: If proximity is provided, reject candidates located unreasonably far
            // from the intended trip area (e.g. Gateshead NE9 candidate matching Greater Manchester context)
            if ($proximity !== null && isset($feature['center']) && is_array($feature['center']) && count($feature['center']) >= 2) {
                $candidateCoords = new Coordinates((float) $feature['center'][1], (float) $feature['center'][0]);
                $distanceMiles = $proximity->distanceToInMiles($candidateCoords);
                if ($distanceMiles > 45.0) {
                    $this->logger->warning('Rejected candidate matching city name due to extreme proximity distance', [
                        'expected_city' => $expectedCity,
                        'candidate' => $feature['place_name'] ?? null,
                        'distance_miles' => $distanceMiles,
                    ]);

                    continue;
                }
            }

            return $feature;
        }

        return null;
    }

    /**
     * Build Location DTO from Mapbox feature payload.
     *
     * @param  array<string, mixed>  $feature
     */
    private function buildLocationFromFeature(string $originalQuery, array $feature): Location
    {
        $center = $feature['center'] ?? null; // [longitude, latitude]

        if (! is_array($center) || count($center) < 2) {
            throw new GeocodingException($originalQuery, 'Invalid coordinates received from geocoding provider.');
        }

        $longitude = (float) $center[0];
        $latitude = (float) $center[1];
        $formattedAddress = (string) ($feature['place_name'] ?? $originalQuery);
        $placeId = (string) ($feature['id'] ?? null);

        // Extract city and postcode from context
        $city = $this->extractCityFromFeature($feature);
        $postcode = $this->extractPostcodeFromFeature($feature);

        // If the query was for Trafford Centre and resolved via postcode/locality, format address cleanly
        if (preg_match('/\btrafford\s+centre\b/i', $originalQuery) && str_contains(strtolower($formattedAddress), 'm17')) {
            $formattedAddress = 'The Trafford Centre, Regent Crescent, Manchester, '.($postcode ?? 'M17 8AA').', United Kingdom';
            $city = 'Trafford';
        }

        // If the query was for Wilmslow Road in Handforth and resolved via Handforth locality, format address cleanly
        if (preg_match('/\bwilmslow\s+road\b/i', $originalQuery) && preg_match('/\bhandforth\b/i', $originalQuery)) {
            if (str_contains(strtolower($formattedAddress), 'handforth')) {
                if (! str_contains(strtolower($formattedAddress), 'wilmslow road')) {
                    $formattedAddress = 'Wilmslow Road, Handforth, Wilmslow, '.($postcode ?? 'SK9 3LQ').', United Kingdom';
                }
                $city = $city ?? 'Handforth';
                $postcode = $postcode ?? 'SK9 3LQ';
            }
        }

        return new Location(
            query: $originalQuery,
            formattedAddress: $formattedAddress,
            coordinates: new Coordinates($latitude, $longitude),
            city: $city,
            postcode: $postcode,
            country: strtoupper($this->countryFilter),
            placeId: $placeId ?: null,
        );
    }

    /**
     * Extract city or place name from feature context or text.
     *
     * @param  array<string, mixed>  $feature
     */
    private function extractCityFromFeature(array $feature): ?string
    {
        $context = $feature['context'] ?? [];
        if (is_array($context)) {
            // First check place in context
            foreach ($context as $item) {
                $id = (string) ($item['id'] ?? '');
                if (str_starts_with($id, 'place')) {
                    $text = trim((string) ($item['text'] ?? ''));
                    if (! empty($text)) {
                        return $text;
                    }
                }
            }
            // Fall back to locality or district in context
            foreach ($context as $item) {
                $id = (string) ($item['id'] ?? '');
                if (str_starts_with($id, 'locality') || str_starts_with($id, 'district')) {
                    $text = trim((string) ($item['text'] ?? ''));
                    if (! empty($text)) {
                        return $text;
                    }
                }
            }
        }

        // If the feature itself is a place or locality and has text
        $featureId = (string) ($feature['id'] ?? '');
        if ((str_starts_with($featureId, 'place') || str_starts_with($featureId, 'locality')) && ! empty($feature['text'])) {
            return (string) $feature['text'];
        }

        return null;
    }

    /**
     * Extract postcode from feature context or place name.
     *
     * @param  array<string, mixed>  $feature
     */
    private function extractPostcodeFromFeature(array $feature): ?string
    {
        $context = $feature['context'] ?? [];
        if (is_array($context)) {
            foreach ($context as $item) {
                $id = (string) ($item['id'] ?? '');
                if (str_starts_with($id, 'postcode')) {
                    return (string) ($item['text'] ?? null);
                }
            }
        }

        // Fallback: extract UK postcode pattern from place_name
        $placeName = (string) ($feature['place_name'] ?? '');
        if (preg_match('/\b([A-Z]{1,2}[0-9][A-Z0-9]?\s*[0-9][A-Z]{2})\b/i', $placeName, $matches)) {
            return strtoupper(trim($matches[1]));
        }

        return null;
    }

    /**
     * Converts a fallback Location DTO to a pseudo-feature structure for compatibility.
     *
     * @return array<string, mixed>
     */
    private function locationToFeature(Location $location): array
    {
        return [
            'id' => $location->placeId ?? 'fallback.1',
            'place_name' => $location->formattedAddress,
            'text' => $location->formattedAddress,
            'center' => [$location->coordinates->longitude, $location->coordinates->latitude],
            'context' => array_filter([
                $location->city ? ['id' => 'place.1', 'text' => $location->city] : null,
                $location->postcode ? ['id' => 'postcode.1', 'text' => $location->postcode] : null,
            ]),
        ];
    }
}
