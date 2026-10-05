<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Location\DTOs\Coordinates;
use App\Domain\Location\DTOs\Location;
use App\Domain\Location\DTOs\RouteInformation;
use App\Domain\Location\Exceptions\GeocodingException;
use App\Domain\Location\Exceptions\RouteCalculationException;
use App\Domain\Location\Services\CachedGeocodingService;
use App\Domain\Location\Services\CachedRouteService;
use App\Domain\Location\Services\MapboxGeocodingService;
use App\Domain\Location\Services\MapboxRouteService;
use App\Domain\Taxi\Contracts\EstimateEngineInterface;
use App\Domain\Taxi\DTOs\TripRequest;
use App\Domain\Taxi\Enums\TaxiProvider;
use App\Domain\Taxi\Services\TaxiComparisonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Psr\Log\NullLogger;
use Tests\TestCase;

class RealMapServicesTest extends TestCase
{
    use RefreshDatabase;

    private MapboxGeocodingService $geocodingService;

    private MapboxRouteService $routeService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->geocodingService = new MapboxGeocodingService(
            apiKey: 'pk.test_mock_token_123',
            logger: new NullLogger,
            timeoutSeconds: 5,
            countryFilter: 'gb',
            fallbackDriver: null,
        );

        $this->routeService = new MapboxRouteService(
            apiKey: 'pk.test_mock_token_123',
            logger: new NullLogger,
            timeoutSeconds: 5,
            fallbackDriver: null,
        );
    }

    // 1. Successful geocoding
    public function test_1_successful_geocoding(): void
    {
        Http::fake([
            'https://api.mapbox.com/geocoding/v5/mapbox.places/*' => Http::response([
                'features' => [
                    [
                        'id' => 'place.12345',
                        'place_name' => 'Manchester Airport (MAN), Ringway, Manchester M90 1QX, UK',
                        'center' => [-2.2727, 53.3588], // [lng, lat]
                        'context' => [
                            ['id' => 'postcode.1', 'text' => 'M90 1QX'],
                            ['id' => 'place.2', 'text' => 'Manchester'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $location = $this->geocodingService->geocode('Manchester Airport');

        $this->assertEquals('Manchester Airport', $location->query);
        $this->assertEquals(53.3588, $location->coordinates->latitude);
        $this->assertEquals(-2.2727, $location->coordinates->longitude);
        $this->assertEquals('Manchester', $location->city);
        $this->assertEquals('M90 1QX', $location->postcode);
        $this->assertStringContainsString('Manchester Airport', $location->formattedAddress);
    }

    // 2. Address not found
    public function test_2_address_not_found(): void
    {
        $this->expectException(GeocodingException::class);
        $this->expectExceptionMessage('No location found for "NonExistentPlaceXYZ123"');

        Http::fake([
            'https://api.mapbox.com/geocoding/v5/mapbox.places/*' => Http::response([
                'features' => [],
            ], 200),
        ]);

        $this->geocodingService->geocode('NonExistentPlaceXYZ123');
    }

    // 3. Geocoding provider failure
    public function test_3_geocoding_provider_failure(): void
    {
        $this->expectException(GeocodingException::class);

        Http::fake([
            'https://api.mapbox.com/geocoding/v5/mapbox.places/*' => Http::response([
                'message' => 'Internal Server Error',
            ], 500),
        ]);

        $this->geocodingService->geocode('Manchester Piccadilly');
    }

    // 4. Successful routing
    public function test_4_successful_routing(): void
    {
        Http::fake([
            'https://api.mapbox.com/directions/v5/mapbox/driving/*' => Http::response([
                'code' => 'Ok',
                'routes' => [
                    [
                        'distance' => 17500.0, // 17.5 km (~10.87 miles)
                        'duration' => 1500.0, // 25 mins
                        'geometry' => [
                            'coordinates' => [[-2.27, 53.35], [-2.24, 53.48]],
                        ],
                        'legs' => [
                            ['summary' => 'A5103 Princess Pkwy'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $origin = new Location('Origin', 'Origin, UK', new Coordinates(53.3588, -2.2727));
        $dest = new Location('Dest', 'Dest, UK', new Coordinates(53.4808, -2.2426));

        $route = $this->routeService->calculateRoute($origin, $dest);

        $this->assertEquals(17500, $route->distanceMeters);
        $this->assertEquals(10.87, $route->distanceMiles);
        $this->assertEquals(1500, $route->durationSeconds);
        $this->assertEquals(25, $route->durationMinutes);
        $this->assertEquals('Via A5103 Princess Pkwy', $route->summary);
        $this->assertFalse($route->isEstimated); // Actual road route!
    }

    // 5. Routing provider failure
    public function test_5_routing_provider_failure(): void
    {
        $this->expectException(RouteCalculationException::class);

        Http::fake([
            'https://api.mapbox.com/directions/v5/mapbox/driving/*' => Http::response([
                'message' => 'Too Many Requests',
            ], 429),
        ]);

        $origin = new Location('Origin', 'Origin, UK', new Coordinates(53.3588, -2.2727));
        $dest = new Location('Dest', 'Dest, UK', new Coordinates(53.4808, -2.2426));

        $this->routeService->calculateRoute($origin, $dest);
    }

    // 6. Invalid coordinates
    public function test_6_invalid_coordinates(): void
    {
        $this->expectException(RouteCalculationException::class);

        $origin = new Location('Origin', 'Origin, UK', new Coordinates(53.0, -2.0));
        $identical = new Location('Dest', 'Dest, UK', new Coordinates(53.0, -2.0));

        $this->routeService->calculateRoute($origin, $identical);
    }

    // 7. Cached geocoding result
    public function test_7_cached_geocoding_result(): void
    {
        Cache::flush();

        Http::fake([
            'https://api.mapbox.com/geocoding/v5/mapbox.places/*' => Http::response([
                'features' => [
                    [
                        'id' => 'place.1',
                        'place_name' => 'Manchester Piccadilly, UK',
                        'center' => [-2.2312, 53.4774],
                    ],
                ],
            ], 200),
        ]);

        $cachedService = new CachedGeocodingService(
            inner: $this->geocodingService,
            cache: Cache::store('array'),
            logger: new NullLogger,
            ttl: 3600,
        );

        $res1 = $cachedService->geocode('Manchester Piccadilly');
        $res2 = $cachedService->geocode('Manchester Piccadilly');

        $this->assertEquals($res1->formattedAddress, $res2->formattedAddress);
        // Only 1 HTTP call made
        Http::assertSentCount(1);
    }

    // 8. Cached route result
    public function test_8_cached_route_result(): void
    {
        Cache::flush();

        Http::fake([
            'https://api.mapbox.com/directions/v5/mapbox/driving/*' => Http::response([
                'code' => 'Ok',
                'routes' => [
                    ['distance' => 10000.0, 'duration' => 600.0],
                ],
            ], 200),
        ]);

        $cachedRoute = new CachedRouteService(
            inner: $this->routeService,
            cache: Cache::store('array'),
            logger: new NullLogger,
            ttl: 3600,
        );

        $origin = new Location('Origin', 'Origin, UK', new Coordinates(53.4, -2.2));
        $dest = new Location('Dest', 'Dest, UK', new Coordinates(53.5, -2.3));

        $r1 = $cachedRoute->calculateRoute($origin, $dest);
        $r2 = $cachedRoute->calculateRoute($origin, $dest);

        $this->assertEquals($r1->distanceMiles, $r2->distanceMiles);
        Http::assertSentCount(1);
    }

    // 9. Correct miles conversion if provider returns meters
    public function test_9_correct_miles_conversion(): void
    {
        Http::fake([
            'https://api.mapbox.com/directions/v5/mapbox/driving/*' => Http::response([
                'code' => 'Ok',
                'routes' => [
                    ['distance' => 16093.44, 'duration' => 1200.0], // Exactly 10.0 miles
                ],
            ], 200),
        ]);

        $origin = new Location('A', 'A, UK', new Coordinates(53.4, -2.2));
        $dest = new Location('B', 'B, UK', new Coordinates(53.5, -2.3));

        $route = $this->routeService->calculateRoute($origin, $dest);
        $this->assertEquals(10.0, $route->distanceMiles);
    }

    // 10. Correct minutes conversion if provider returns seconds
    public function test_10_correct_minutes_conversion(): void
    {
        Http::fake([
            'https://api.mapbox.com/directions/v5/mapbox/driving/*' => Http::response([
                'code' => 'Ok',
                'routes' => [
                    ['distance' => 10000.0, 'duration' => 1800.0], // Exactly 30 minutes
                ],
            ], 200),
        ]);

        $origin = new Location('A', 'A, UK', new Coordinates(53.4, -2.2));
        $dest = new Location('B', 'B, UK', new Coordinates(53.5, -2.3));

        $route = $this->routeService->calculateRoute($origin, $dest);
        $this->assertEquals(30, $route->durationMinutes);
    }

    // 11. Full compare flow using mocked real-service responses
    public function test_11_full_compare_flow_using_mocked_real_services(): void
    {
        $this->seed();

        Http::fake([
            'https://api.mapbox.com/geocoding/v5/mapbox.places/Manchester%20Airport*' => Http::response([
                'features' => [
                    [
                        'id' => 'place.man',
                        'place_name' => 'Manchester Airport (MAN), Manchester, UK',
                        'center' => [-2.2727, 53.3588],
                    ],
                ],
            ], 200),
            'https://api.mapbox.com/geocoding/v5/mapbox.places/Manchester%20City%20Centre*' => Http::response([
                'features' => [
                    [
                        'id' => 'place.city',
                        'place_name' => 'Manchester City Centre, UK',
                        'center' => [-2.2426, 53.4808],
                    ],
                ],
            ], 200),
            'https://api.mapbox.com/directions/v5/mapbox/driving/*' => Http::response([
                'code' => 'Ok',
                'routes' => [
                    [
                        'distance' => 17550.0,
                        'duration' => 1620.0,
                        'legs' => [['summary' => 'M56 & Princess Pkwy']],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/compare', [
            'pickup' => 'Manchester Airport',
            'dropoff' => 'Manchester City Centre',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.route.distance_miles', 10.91)
            ->assertJsonPath('data.route.duration_minutes', 27);

        $quotes = $response->json('data.quotes');
        $this->assertCount(4, $quotes);

        foreach ($quotes as $quote) {
            $this->assertTrue($quote['is_available']);
            $this->assertEquals('estimate', $quote['quote_type']);
            $this->assertGreaterThan(0.0, $quote['min_price']);
            $this->assertGreaterThan(0.0, $quote['max_price']);
        }
    }

    // 12. Existing fare calculation still works using RouteInformation
    public function test_12_existing_fare_calculation_still_works_using_route_information(): void
    {
        $this->seed();

        $origin = new Location('Manchester Airport', 'Manchester Airport (MAN), UK', new Coordinates(53.3588, -2.2727));
        $dest = new Location('Manchester Piccadilly', 'Manchester Piccadilly, UK', new Coordinates(53.4774, -2.2312));

        $route = RouteInformation::fromCalculatedValues(
            origin: $origin,
            destination: $dest,
            distanceMeters: 16093, // ~10 miles
            durationSeconds: 1200, // 20 mins
        );

        $engine = app(EstimateEngineInterface::class);
        $trip = new TripRequest('Manchester Airport', 'Manchester Piccadilly');

        $quote = $engine->calculateEstimate(TaxiProvider::UBER, $trip, $route);

        $this->assertEquals(TaxiProvider::UBER, $quote->provider);
        $this->assertTrue($quote->isAvailable);
        $this->assertGreaterThan(15.0, $quote->priceRange->min);
        $this->assertGreaterThan(15.0, $quote->priceRange->max);
    }

    // 13. Manchester Airport resolves to airport terminal
    public function test_13_manchester_airport_resolves_to_terminal_location(): void
    {
        Http::fake([
            'https://api.mapbox.com/geocoding/v5/mapbox.places/Manchester%20Airport*' => Http::response([
                'features' => [
                    [
                        'id' => 'place.man',
                        'place_name' => 'Manchester Airport (MAN), Ringway, Manchester M90 1QX, UK',
                        'center' => [-2.2727, 53.3588],
                        'context' => [
                            ['id' => 'postcode.1', 'text' => 'M90 1QX'],
                            ['id' => 'place.2', 'text' => 'Manchester'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $location = $this->geocodingService->geocode('Manchester Airport');

        $this->assertEquals('Manchester Airport', $location->query);
        $this->assertEquals('Manchester', $location->city);
        $this->assertEquals('M90 1QX', $location->postcode);
        $this->assertEquals(53.3588, $location->coordinates->latitude);
        $this->assertEquals(-2.2727, $location->coordinates->longitude);
        $this->assertStringContainsString('Manchester Airport', $location->formattedAddress);
    }

    // 14. Manchester City Centre resolves correctly
    public function test_14_manchester_city_centre_resolves_correctly(): void
    {
        Http::fake([
            'https://api.mapbox.com/geocoding/v5/mapbox.places/Manchester%20City%20Centre*' => Http::response([
                'features' => [
                    [
                        'id' => 'neighborhood.9595983',
                        'place_name' => 'City Centre, Manchester, Greater Manchester, England, United Kingdom',
                        'center' => [-2.243362, 53.481186],
                        'context' => [
                            ['id' => 'postcode.1', 'text' => 'M2 4LQ'],
                            ['id' => 'place.2', 'text' => 'Manchester'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $location = $this->geocodingService->geocode('Manchester City Centre');

        $this->assertEquals('Manchester City Centre', $location->query);
        $this->assertEquals('Manchester', $location->city);
        $this->assertEquals('M2 4LQ', $location->postcode);
        $this->assertEquals(53.481186, $location->coordinates->latitude);
        $this->assertEquals(-2.243362, $location->coordinates->longitude);
        $this->assertStringContainsString('City Centre', $location->formattedAddress);
    }

    // 15. Manchester City Centre Portland Street resolves to Manchester, not Norwich
    public function test_15_manchester_city_centre_portland_street_hotel_resolves_to_manchester_not_norwich(): void
    {
        Http::fake([
            'https://api.mapbox.com/geocoding/v5/mapbox.places/Manchester%20City%20Centre%20*hotel*' => Http::response([
                'features' => [
                    [
                        'id' => 'address.norwich',
                        'place_name' => 'Portland Street, Norwich, NR2 3LF, United Kingdom',
                        'center' => [1.274308, 52.624111],
                        'context' => [
                            ['id' => 'postcode.1', 'text' => 'NR2 3LF'],
                            ['id' => 'place.1', 'text' => 'Norwich'],
                        ],
                    ],
                ],
            ], 200),
            'https://api.mapbox.com/geocoding/v5/mapbox.places/Portland%20Street*' => Http::response([
                'features' => [
                    [
                        'id' => 'address.mcr',
                        'place_name' => 'Portland Street, Manchester, M1 4GS, United Kingdom',
                        'center' => [-2.238532, 53.478499],
                        'context' => [
                            ['id' => 'postcode.1', 'text' => 'M1 4GS'],
                            ['id' => 'place.1', 'text' => 'Manchester'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $location = $this->geocodingService->geocode('Manchester City Centre (Portland Street) hotel');

        $this->assertEquals('Manchester City Centre (Portland Street) hotel', $location->query);
        $this->assertEquals('Manchester', $location->city);
        $this->assertEquals('M1 4GS', $location->postcode);
        $this->assertEquals(53.478499, $location->coordinates->latitude);
        $this->assertEquals(-2.238532, $location->coordinates->longitude);
        $this->assertStringContainsString('Manchester', $location->formattedAddress);
        $this->assertStringNotContainsString('Norwich', $location->formattedAddress);
    }

    // 16. Ambiguous Portland Street query with city context
    public function test_16_ambiguous_portland_street_query_with_city_context(): void
    {
        Http::fake([
            'https://api.mapbox.com/geocoding/v5/mapbox.places/Portland%20Street%2C%20Manchester*' => Http::response([
                'features' => [
                    [
                        'id' => 'address.mcr',
                        'place_name' => 'Portland Street, Manchester, M1 4GS, United Kingdom',
                        'center' => [-2.238532, 53.478499],
                        'context' => [
                            ['id' => 'postcode.1', 'text' => 'M1 4GS'],
                            ['id' => 'place.1', 'text' => 'Manchester'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $location = $this->geocodingService->geocode('Portland Street, Manchester');

        $this->assertEquals('Manchester', $location->city);
        $this->assertEquals('M1 4GS', $location->postcode);
        $this->assertStringContainsString('Manchester', $location->formattedAddress);
    }

    // 17. Wrong-city geocoding result is rejected
    public function test_17_wrong_city_geocoding_result_is_rejected(): void
    {
        $this->expectException(GeocodingException::class);
        $this->expectExceptionMessage('Could not confidently resolve the location in Manchester');

        Http::fake([
            'https://api.mapbox.com/geocoding/v5/mapbox.places/*' => Http::response([
                'features' => [
                    [
                        'id' => 'address.norwich',
                        'place_name' => 'Random Street, Norwich, NR2 3LF, United Kingdom',
                        'center' => [1.274308, 52.624111],
                        'context' => [
                            ['id' => 'postcode.1', 'text' => 'NR2 3LF'],
                            ['id' => 'place.1', 'text' => 'Norwich'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->geocodingService->geocode('Manchester CompletelyFakeRoad 123');
    }

    // 18. Airport result selection prefers airport place over arbitrary road
    public function test_18_airport_result_selection_prefers_place_over_arbitrary_road(): void
    {
        Http::fake([
            'https://api.mapbox.com/geocoding/v5/mapbox.places/Manchester%20Airport*' => Http::response([
                'features' => [
                    [
                        'id' => 'address.road_1',
                        'place_name' => 'Manchester Airport Eastern Link Road, Stockport, SK7 1RB, United Kingdom',
                        'center' => [-2.178663, 53.351714],
                        'context' => [
                            ['id' => 'postcode.1', 'text' => 'SK7 1RB'],
                            ['id' => 'place.1', 'text' => 'Stockport'],
                        ],
                    ],
                    [
                        'id' => 'place.airport_1',
                        'place_name' => 'Manchester Airport (MAN), Ringway, Manchester M90 1QX, UK',
                        'center' => [-2.2727, 53.3588],
                        'context' => [
                            ['id' => 'postcode.2', 'text' => 'M90 1QX'],
                            ['id' => 'place.2', 'text' => 'Manchester'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $location = $this->geocodingService->geocode('Manchester Airport');

        // Confirms it chose the airport place, NOT the Eastern Link Road!
        $this->assertEquals('Manchester', $location->city);
        $this->assertEquals('M90 1QX', $location->postcode);
        $this->assertEquals(-2.2727, $location->coordinates->longitude);
        $this->assertEquals(53.3588, $location->coordinates->latitude);
        $this->assertStringContainsString('Manchester Airport (MAN)', $location->formattedAddress);
        $this->assertStringNotContainsString('Eastern Link Road', $location->formattedAddress);
    }

    // 19. Same-city route sanity validation rejects suspicious route
    public function test_19_same_city_route_sanity_validation_rejects_suspicious_distance(): void
    {
        $this->seed();

        $this->expectException(RouteCalculationException::class);
        $this->expectExceptionMessage('Calculated route distance (237.6 miles) is inconsistent with the requested local journey');

        Http::fake([
            'https://api.mapbox.com/geocoding/v5/mapbox.places/Manchester%20Airport*' => Http::response([
                'features' => [
                    [
                        'id' => 'place.man',
                        'place_name' => 'Manchester Airport (MAN), Manchester, UK',
                        'center' => [-2.2727, 53.3588],
                        'context' => [['id' => 'place.1', 'text' => 'Manchester']],
                    ],
                ],
            ], 200),
            'https://api.mapbox.com/geocoding/v5/mapbox.places/Portland%20Street*' => Http::response([
                'features' => [
                    [
                        'id' => 'address.mcr',
                        'place_name' => 'Portland Street, Manchester, UK',
                        'center' => [-2.2385, 53.4785],
                        'context' => [['id' => 'place.1', 'text' => 'Manchester']],
                    ],
                ],
            ], 200),
            'https://api.mapbox.com/directions/v5/mapbox/driving/*' => Http::response([
                'code' => 'Ok',
                'routes' => [
                    [
                        'distance' => 382382.0, // 237.6 miles
                        'duration' => 16620.0, // 277 minutes
                        'legs' => [['summary' => 'M62, A1, A47']],
                    ],
                ],
            ], 200),
        ]);

        $comparisonService = app(TaxiComparisonService::class);
        $trip = new TripRequest('Manchester Airport', 'Portland Street, Manchester');

        $comparisonService->compare($trip);
    }

    // 20. Invalid unresolvable address throws controlled GeocodingException
    public function test_20_invalid_unresolvable_address_throws_controlled_geocoding_exception(): void
    {
        $this->expectException(GeocodingException::class);
        $this->expectExceptionMessage('No location found for "CompletelyInvalidQuery777"');

        Http::fake([
            'https://api.mapbox.com/geocoding/v5/mapbox.places/*' => Http::response([
                'features' => [],
            ], 200),
        ]);

        $this->geocodingService->geocode('CompletelyInvalidQuery777');
    }

    // 21. A. "Vision Express" from Heathrow Airport must NOT resolve to Whiston/Merseyside
    public function test_21_vision_express_from_heathrow_does_not_resolve_to_whiston_merseyside(): void
    {
        $this->expectException(GeocodingException::class);
        $this->expectExceptionMessage('The destination "Vision Express" is ambiguous');

        $heathrow = new Coordinates(51.4700, -0.4543);

        Http::fake([
            'https://api.mapbox.com/geocoding/v5/mapbox.places/Vision%20Express*' => Http::response([
                'features' => [
                    [
                        'id' => 'locality.204401231',
                        'place_name' => 'Whiston, Merseyside, England, United Kingdom',
                        'text' => 'Whiston',
                        'center' => [-2.790232, 53.41682], // 172 miles away
                        'context' => [['id' => 'place.1', 'text' => 'Prescot']],
                    ],
                    [
                        'id' => 'address.12345',
                        'place_name' => 'Express Way, Newbury, RG14 5TX, United Kingdom',
                        'text' => 'Express Way',
                        'center' => [-1.293218, 51.399926],
                    ],
                ],
            ], 200),
        ]);

        $location = $this->geocodingService->geocode('Vision Express', $heathrow, 'London');

        // Safety assertion in case exception was not thrown
        $this->assertNotEquals('Whiston', $location->city);
        $this->assertStringNotContainsString('Merseyside', $location->formattedAddress);
    }

    // 22. B. Generic POI with nearby valid candidate - nearby candidate is preferred
    public function test_22_generic_poi_with_nearby_candidate_prefers_nearby_candidate(): void
    {
        $heathrow = new Coordinates(51.4700, -0.4543);

        Http::fake([
            'https://api.mapbox.com/geocoding/v5/mapbox.places/Tesco*' => Http::response([
                'features' => [
                    [
                        'id' => 'poi.distant',
                        'place_name' => 'Tesco Extra, Edinburgh, EH12 9JR, United Kingdom',
                        'text' => 'Tesco Extra',
                        'center' => [-3.188267, 55.953252], // 330 miles away
                    ],
                    [
                        'id' => 'poi.nearby',
                        'place_name' => 'Tesco Express, High Street, Hounslow, TW3 1LD, United Kingdom',
                        'text' => 'Tesco Express',
                        'center' => [-0.36545, 51.47043], // 3.8 miles away
                        'context' => [
                            ['id' => 'place.1', 'text' => 'Hounslow'],
                            ['id' => 'region.1', 'text' => 'Greater London'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $location = $this->geocodingService->geocode('Tesco', $heathrow, 'London');

        $this->assertEquals('poi.nearby', $location->placeId);
        $this->assertStringContainsString('Hounslow', $location->formattedAddress);
        $this->assertStringNotContainsString('Edinburgh', $location->formattedAddress);
    }

    // 23. C. Explicit destination such as "Vision Express, [specific London address]" is preferred
    public function test_23_explicit_destination_prefers_exact_specific_address(): void
    {
        $heathrow = new Coordinates(51.4700, -0.4543);

        Http::fake([
            'https://api.mapbox.com/geocoding/v5/mapbox.places/*' => Http::response([
                'features' => [
                    [
                        'id' => 'address.oxford_st',
                        'place_name' => '181-183 Oxford Street, Westminster, London, W1D 2JT, United Kingdom',
                        'text' => '181-183 Oxford Street',
                        'center' => [-0.1386, 51.51538],
                        'context' => [
                            ['id' => 'postcode.1', 'text' => 'W1D 2JT'],
                            ['id' => 'place.1', 'text' => 'London'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $location = $this->geocodingService->geocode('Vision Express, 181-183 Oxford Street, London', $heathrow);

        $this->assertEquals('London', $location->city);
        $this->assertEquals('W1D 2JT', $location->postcode);
        $this->assertStringContainsString('Oxford Street', $location->formattedAddress);
    }

    // 24. D. Legitimate long-distance trip still works when destination is explicitly specified
    public function test_24_legitimate_long_distance_trip_works_with_explicit_destination(): void
    {
        $manchesterAirport = new Coordinates(53.3588, -2.2727);

        Http::fake([
            'https://api.mapbox.com/geocoding/v5/mapbox.places/*' => Http::response([
                'features' => [
                    [
                        'id' => 'address.whiston_prescot',
                        'place_name' => 'Vision Express, Warrington Road, Whiston, Prescot, L35 5DR, United Kingdom',
                        'text' => 'Vision Express',
                        'center' => [-2.790232, 53.41682],
                        'context' => [
                            ['id' => 'postcode.1', 'text' => 'L35 5DR'],
                            ['id' => 'place.1', 'text' => 'Prescot'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        // Long-distance trip with explicit city specified must NOT be rejected by POI radius
        $location = $this->geocodingService->geocode('Vision Express, Whiston, Merseyside', $manchesterAirport);

        $this->assertEquals('address.whiston_prescot', $location->placeId);
        $this->assertEquals('L35 5DR', $location->postcode);
        $this->assertStringContainsString('Whiston', $location->formattedAddress);
    }

    // 25. E. Ambiguous POI with no sufficiently confident candidate returns controlled error
    public function test_25_ambiguous_poi_with_no_confident_candidate_returns_controlled_error(): void
    {
        $this->expectException(GeocodingException::class);
        $this->expectExceptionMessage('The destination "Vision Express" is ambiguous');

        $heathrow = new Coordinates(51.4700, -0.4543);

        Http::fake([
            'https://api.mapbox.com/geocoding/v5/mapbox.places/Vision%20Express*' => Http::response([
                'features' => [
                    [
                        'id' => 'address.unrelated',
                        'place_name' => 'Empress Street, Southwark, London, SE17 3HH, United Kingdom',
                        'text' => 'Empress Street',
                        'center' => [-0.095479, 51.484802],
                    ],
                ],
            ], 200),
        ]);

        $this->geocodingService->geocode('Vision Express', $heathrow, 'London');
    }

    // 26. Salford Quays resolves to correct Manchester/Salford Quays area rather than Todmorden
    public function test_26_salford_quays_resolves_to_correct_manchester_salford_area(): void
    {
        $piccadilly = new Coordinates(53.4774, -2.2312);

        Http::fake([
            // Unrefined query: returns Todmorden as feature 0
            'https://api.mapbox.com/geocoding/v5/mapbox.places/Salford%20Quays%2C%20Salford%2C%20Manchester*' => Http::response([
                'features' => [
                    [
                        'id' => 'address.todmorden',
                        'place_name' => 'Salford, Todmorden, OL14 7LF, United Kingdom',
                        'text' => 'Salford',
                        'center' => [-2.100234, 53.712032],
                        'context' => [
                            ['id' => 'place.todmorden', 'text' => 'Todmorden'],
                            ['id' => 'district.wyorkshire', 'text' => 'West Yorkshire'],
                        ],
                    ],
                ],
            ], 200),
            // Refined query: "Salford Quays" returns correct neighborhood
            'https://api.mapbox.com/geocoding/v5/mapbox.places/Salford%20Quays*' => Http::response([
                'features' => [
                    [
                        'id' => 'neighborhood.salford_quays',
                        'place_name' => 'Salford Quays, Salford, Greater Manchester, England, United Kingdom',
                        'text' => 'Salford Quays',
                        'place_type' => ['neighborhood'],
                        'center' => [-2.293708, 53.470703],
                        'context' => [
                            ['id' => 'place.salford', 'text' => 'Salford'],
                            ['id' => 'district.gm', 'text' => 'Greater Manchester'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $location = $this->geocodingService->geocode('Salford Quays, Salford, Manchester', $piccadilly);

        $this->assertEquals('Salford', $location->city);
        $this->assertEqualsWithDelta(53.4707, $location->coordinates->latitude, 0.05);
        $this->assertEqualsWithDelta(-2.2937, $location->coordinates->longitude, 0.05);
        $this->assertStringContainsString('Salford Quays', $location->formattedAddress);
        $this->assertStringNotContainsString('Todmorden', $location->formattedAddress);
    }

    // 27. Trafford Centre resolves to Greater Manchester rather than Gateshead
    public function test_27_trafford_centre_resolves_to_greater_manchester_rather_than_gateshead(): void
    {
        $piccadilly = new Coordinates(53.4774, -2.2312);

        Http::fake([
            // Mapbox query for "Trafford Centre" returns Gateshead NE9 6NG
            'https://api.mapbox.com/geocoding/v5/mapbox.places/Trafford%20Centre*' => Http::response([
                'features' => [
                    [
                        'id' => 'address.gateshead',
                        'place_name' => 'Trafford, Gateshead, NE9 6NG, United Kingdom',
                        'text' => 'Trafford',
                        'center' => [-1.593443, 54.920413],
                        'context' => [
                            ['id' => 'postcode.gateshead', 'text' => 'NE9 6NG'],
                            ['id' => 'place.gateshead', 'text' => 'Gateshead'],
                            ['id' => 'district.tynewear', 'text' => 'Tyne and Wear'],
                        ],
                    ],
                ],
            ], 200),
            // Refined query for The Trafford Centre returns M17 Greater Manchester
            'https://api.mapbox.com/geocoding/v5/mapbox.places/The%20Trafford%20Centre*' => Http::response([
                'features' => [
                    [
                        'id' => 'postcode.m17',
                        'place_name' => 'M17 8AA, Manchester, Greater Manchester, England, United Kingdom',
                        'text' => 'M17 8AA',
                        'place_type' => ['postcode'],
                        'center' => [-2.349829, 53.465709],
                        'context' => [
                            ['id' => 'postcode.1', 'text' => 'M17 8AA'],
                            ['id' => 'place.manchester', 'text' => 'Manchester'],
                            ['id' => 'district.gm', 'text' => 'Greater Manchester'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $location = $this->geocodingService->geocode('Trafford Centre, Select pickup point', $piccadilly);

        $this->assertEqualsWithDelta(53.4657, $location->coordinates->latitude, 0.05);
        $this->assertEqualsWithDelta(-2.3498, $location->coordinates->longitude, 0.05);
        $this->assertEquals('M17 8AA', $location->postcode);
        $this->assertStringNotContainsString('Gateshead', $location->formattedAddress);
        $this->assertStringNotContainsString('NE9', $location->formattedAddress);
    }

    // 28. Wrong-city candidate is rejected when it contradicts city context
    public function test_28_wrong_city_candidate_is_rejected_when_it_contradicts_city_context(): void
    {
        $this->expectException(GeocodingException::class);
        $this->expectExceptionMessage('Could not confidently resolve the location in Manchester');

        $piccadilly = new Coordinates(53.4774, -2.2312);

        Http::fake([
            'https://api.mapbox.com/geocoding/v5/mapbox.places/*' => Http::response([
                'features' => [
                    [
                        'id' => 'address.norwich',
                        'place_name' => 'Portland Street, Norwich, NR2 3LF, United Kingdom',
                        'text' => 'Portland Street',
                        'center' => [1.2882, 52.6288],
                        'context' => [
                            ['id' => 'place.norwich', 'text' => 'Norwich'],
                            ['id' => 'district.norfolk', 'text' => 'Norfolk'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->geocodingService->geocode('Portland Street, Manchester', $piccadilly);
    }

    // 29. Wilmslow Road in Handforth resolves to Handforth/Wilmslow rather than Altrincham
    public function test_29_wilmslow_road_handforth_resolves_to_handforth_not_altrincham(): void
    {
        $manchesterAirport = new Coordinates(53.3588, -2.2727);

        Http::fake([
            // Initial query with proximity returns Altrincham as feature 0 due to road name & airport proximity
            'https://api.mapbox.com/geocoding/v5/mapbox.places/Wilmslow%20Road%2C%20Handforth%2C%20Wilmslow%2C%20UK*' => Http::response([
                'features' => [
                    [
                        'id' => 'address.altrincham',
                        'place_name' => 'Wilmslow Road, Altrincham, WA15 0AF, United Kingdom',
                        'text' => 'Wilmslow Road',
                        'center' => [-2.291313, 53.353186],
                        'context' => [
                            ['id' => 'postcode.1', 'text' => 'WA15 0AF'],
                            ['id' => 'place.1', 'text' => 'Altrincham'],
                            ['id' => 'district.gm', 'text' => 'Greater Manchester'],
                        ],
                    ],
                ],
            ], 200),
            // Refined candidate query for Handforth returns Handforth locality
            'https://api.mapbox.com/geocoding/v5/mapbox.places/Wilmslow%20Road%2C%20Handforth*' => Http::response([
                'features' => [
                    [
                        'id' => 'locality.handforth',
                        'place_name' => 'Handforth, Cheshire East, England, United Kingdom',
                        'text' => 'Handforth',
                        'place_type' => ['locality'],
                        'center' => [-2.215352, 53.35034],
                        'context' => [
                            ['id' => 'district.cheshire_east', 'text' => 'Cheshire East'],
                            ['id' => 'region.england', 'text' => 'England'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $location = $this->geocodingService->geocode('Wilmslow Road, Handforth, Wilmslow, UK', $manchesterAirport);

        $this->assertEqualsWithDelta(53.3503, $location->coordinates->latitude, 0.05);
        $this->assertEqualsWithDelta(-2.2153, $location->coordinates->longitude, 0.05);
        $this->assertStringContainsString('Handforth', $location->formattedAddress);
        $this->assertStringContainsString('Wilmslow', $location->formattedAddress);
        $this->assertStringNotContainsString('Altrincham', $location->formattedAddress);
        $this->assertStringNotContainsString('WA15', $location->formattedAddress);
    }

    // 30. Stockport Railway Station resolves to actual station SK3 9HZ, NOT Marple / SK6 6NY
    public function test_30_stockport_railway_station_resolves_to_actual_station_not_marple(): void
    {
        Http::fake([
            // Mapbox places API returns Marple residential street address for Stockport Railway Station
            'https://api.mapbox.com/geocoding/v5/mapbox.places/*' => Http::response([
                'features' => [
                    [
                        'id' => 'address.985745453121306',
                        'place_name' => 'Station Road, Marple, Stockport, SK6 6NY, United Kingdom',
                        'text' => 'Station Road',
                        'center' => [-2.063694, 53.397003],
                        'context' => [
                            ['id' => 'postcode.1', 'text' => 'SK6 6NY'],
                            ['id' => 'place.1', 'text' => 'Stockport'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $location = $this->geocodingService->geocode('Stockport Railway Station, Station Road, Stockport, UK');

        // Confirms it resolves to actual Stockport Railway Station around SK3 9HZ, NOT Marple
        $this->assertEquals('Stockport', $location->city);
        $this->assertEquals('SK3 9HZ', $location->postcode);
        $this->assertNotEquals('SK6 6NY', $location->postcode);
        $this->assertEqualsWithDelta(53.4074, $location->coordinates->latitude, 0.01);
        $this->assertEqualsWithDelta(-2.1634, $location->coordinates->longitude, 0.01);
        $this->assertStringContainsString('Stockport Railway Station', $location->formattedAddress);
        $this->assertStringNotContainsString('Marple', $location->formattedAddress);
        $this->assertStringNotContainsString('SK6 6NY', $location->formattedAddress);
    }

    // 31. Ordinary address on Station Road does not trigger railway station resolver
    public function test_31_ordinary_station_road_address_does_not_trigger_railway_station_resolver(): void
    {
        Http::fake([
            'https://api.mapbox.com/geocoding/v5/mapbox.places/*' => Http::response([
                'features' => [
                    [
                        'id' => 'address.985745453121306',
                        'place_name' => '10 Station Road, Marple, Stockport, SK6 6AL, United Kingdom',
                        'text' => 'Station Road',
                        'center' => [-2.06823, 53.3957],
                        'context' => [
                            ['id' => 'postcode.1', 'text' => 'SK6 6AL'],
                            ['id' => 'place.1', 'text' => 'Stockport'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $location = $this->geocodingService->geocode('10 Station Road, Marple, Stockport, SK6 6AL');

        // Confirms ordinary address resolves as address, not station
        $this->assertEquals('address.985745453121306', $location->placeId);
        $this->assertEquals('SK6 6AL', $location->postcode);
        $this->assertStringContainsString('Marple', $location->formattedAddress);
    }

    // 32. Station POI feature from Mapbox is prioritized over generic road
    public function test_32_station_poi_feature_is_prioritized_over_generic_road(): void
    {
        Http::fake([
            'https://api.mapbox.com/geocoding/v5/mapbox.places/*' => Http::response([
                'features' => [
                    [
                        'id' => 'address.generic_road',
                        'place_name' => 'Station Road, Marple, Stockport, SK6 6NY, United Kingdom',
                        'text' => 'Station Road',
                        'center' => [-2.063694, 53.397003],
                    ],
                    [
                        'id' => 'poi.piccadilly_station',
                        'place_name' => 'Manchester Piccadilly Station, Manchester, M1 2GH, United Kingdom',
                        'text' => 'Manchester Piccadilly Station',
                        'center' => [-2.2312, 53.4774],
                        'properties' => [
                            'category' => 'train station, transport',
                        ],
                        'context' => [
                            ['id' => 'place.1', 'text' => 'Manchester'],
                            ['id' => 'postcode.1', 'text' => 'M1 2GH'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $location = $this->geocodingService->geocode('Manchester Piccadilly Station');

        $this->assertEquals('poi.piccadilly_station', $location->placeId);
        $this->assertEqualsWithDelta(53.4774, $location->coordinates->latitude, 0.01);
        $this->assertEqualsWithDelta(-2.2312, $location->coordinates->longitude, 0.01);
        $this->assertStringContainsString('Manchester Piccadilly Station', $location->formattedAddress);
        $this->assertStringNotContainsString('Marple', $location->formattedAddress);
    }
}
