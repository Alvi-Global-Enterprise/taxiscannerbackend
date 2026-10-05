<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Location\Contracts\GeocodingServiceInterface;
use App\Domain\Location\Contracts\RouteServiceInterface;
use App\Domain\Location\DTOs\Coordinates;
use App\Domain\Location\DTOs\Location;
use App\Domain\Location\DTOs\RouteInformation;
use App\Domain\Location\Exceptions\GeocodingException;
use App\Domain\Location\Exceptions\RouteCalculationException;
use App\Domain\Location\Services\CachedGeocodingService;
use App\Domain\Location\Services\CachedRouteService;
use App\Domain\Location\Services\SimulatedGeocodingService;
use App\Domain\Location\Services\SimulatedRouteService;
use Illuminate\Support\Facades\Cache;
use Psr\Log\NullLogger;
use Tests\TestCase;

class LocationServicesTest extends TestCase
{
    public function test_simulated_geocoding_resolves_known_locations(): void
    {
        $service = new SimulatedGeocodingService;
        $location = $service->geocode('Manchester Airport');

        $this->assertEquals('Manchester Airport', $location->query);
        $this->assertStringContainsString('Manchester Airport', $location->formattedAddress);
        $this->assertEqualsWithDelta(53.3588, $location->coordinates->latitude, 0.001);
        $this->assertEqualsWithDelta(-2.2727, $location->coordinates->longitude, 0.001);
    }

    public function test_geocoding_throws_exception_on_empty_address(): void
    {
        $this->expectException(GeocodingException::class);

        $service = new SimulatedGeocodingService;
        $service->geocode('   ');
    }

    public function test_cached_geocoding_service_uses_cache(): void
    {
        Cache::flush();

        $innerMock = $this->createMock(GeocodingServiceInterface::class);
        $dummyLocation = new Location(
            query: 'Test Address',
            formattedAddress: 'Test Formatted Address, UK',
            coordinates: new Coordinates(53.5, -2.2),
        );

        // innerMock should only be called ONCE
        $innerMock->expects($this->once())
            ->method('geocode')
            ->with('Test Address')
            ->willReturn($dummyLocation);

        $cachedService = new CachedGeocodingService(
            inner: $innerMock,
            cache: Cache::store('array'),
            logger: new NullLogger,
            ttl: 3600,
        );

        $firstResult = $cachedService->geocode('Test Address');
        $secondResult = $cachedService->geocode('Test Address');

        $this->assertEquals($dummyLocation->formattedAddress, $firstResult->formattedAddress);
        $this->assertEquals($dummyLocation->formattedAddress, $secondResult->formattedAddress);
    }

    public function test_simulated_route_calculates_distance_and_duration(): void
    {
        $origin = new Location(
            query: 'Piccadilly',
            formattedAddress: 'Manchester Piccadilly, UK',
            coordinates: new Coordinates(53.4774, -2.2312),
        );

        $destination = new Location(
            query: 'Airport',
            formattedAddress: 'Manchester Airport, UK',
            coordinates: new Coordinates(53.3588, -2.2727),
        );

        $service = new SimulatedRouteService;
        $route = $service->calculateRoute($origin, $destination);

        $this->assertGreaterThan(5000, $route->distanceMeters);
        $this->assertGreaterThan(3.0, $route->distanceMiles);
        $this->assertGreaterThan(600, $route->durationSeconds);
        $this->assertGreaterThan(5, $route->durationMinutes);
    }

    public function test_route_calculation_throws_exception_if_locations_identical(): void
    {
        $this->expectException(RouteCalculationException::class);

        $location = new Location(
            query: 'Same Place',
            formattedAddress: 'Same Place, UK',
            coordinates: new Coordinates(53.4774, -2.2312),
        );

        $service = new SimulatedRouteService;
        $service->calculateRoute($location, $location);
    }

    public function test_cached_route_service_uses_cache(): void
    {
        Cache::flush();

        $origin = new Location('A', 'Address A', new Coordinates(53.4, -2.2));
        $destination = new Location('B', 'Address B', new Coordinates(53.5, -2.3));

        $innerMock = $this->createMock(RouteServiceInterface::class);
        $dummyRoute = RouteInformation::fromCalculatedValues(
            origin: $origin,
            destination: $destination,
            distanceMeters: 12000,
            durationSeconds: 1000,
        );

        $innerMock->expects($this->once())
            ->method('calculateRoute')
            ->willReturn($dummyRoute);

        $cachedService = new CachedRouteService(
            inner: $innerMock,
            cache: Cache::store('array'),
            logger: new NullLogger,
            ttl: 3600,
        );

        $first = $cachedService->calculateRoute($origin, $destination);
        $second = $cachedService->calculateRoute($origin, $destination);

        $this->assertEquals($dummyRoute->distanceMiles, $first->distanceMiles);
        $this->assertEquals($dummyRoute->distanceMiles, $second->distanceMiles);
    }
}
