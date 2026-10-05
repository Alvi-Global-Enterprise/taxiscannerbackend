<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Location\DTOs\Coordinates;
use App\Domain\Location\DTOs\Location;
use App\Domain\Location\DTOs\RouteInformation;
use App\Domain\Pricing\Services\PricingStrategyResolver;
use App\Domain\Pricing\Strategies\BoltPricingStrategy;
use App\Domain\Pricing\Strategies\StreetCarsPricingStrategy;
use App\Domain\Pricing\Strategies\UberPricingStrategy;
use App\Domain\Pricing\Strategies\VeezuPricingStrategy;
use App\Domain\Taxi\DTOs\TripRequest;
use App\Domain\Taxi\Enums\QuoteType;
use App\Domain\Taxi\Enums\TaxiProvider;
use App\Domain\Taxi\Services\EstimateEngine;
use Psr\Log\NullLogger;
use Tests\TestCase;

class EstimateEngineTest extends TestCase
{
    private EstimateEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $resolver = new PricingStrategyResolver([
            new UberPricingStrategy,
            new BoltPricingStrategy,
            new StreetCarsPricingStrategy,
            new VeezuPricingStrategy,
        ]);

        $this->engine = new EstimateEngine($resolver, new NullLogger);
    }

    public function test_calculate_estimate_returns_valid_taxi_quote(): void
    {
        $origin = new Location(
            query: 'Manchester Piccadilly',
            formattedAddress: 'Manchester Piccadilly Station, Manchester, UK',
            coordinates: new Coordinates(53.4774, -2.2312),
        );

        $destination = new Location(
            query: 'Manchester Airport',
            formattedAddress: 'Manchester Airport, Manchester, UK',
            coordinates: new Coordinates(53.3588, -2.2727),
        );

        $route = RouteInformation::fromCalculatedValues(
            origin: $origin,
            destination: $destination,
            distanceMeters: 14500,
            durationSeconds: 1200,
        );

        $trip = new TripRequest(
            pickupQuery: 'Manchester Piccadilly',
            dropoffQuery: 'Manchester Airport',
        );

        $quote = $this->engine->calculateEstimate(TaxiProvider::UBER, $trip, $route);

        $this->assertEquals(TaxiProvider::UBER, $quote->provider);
        $this->assertEquals('Uber', $quote->providerDisplayName);
        $this->assertEquals(QuoteType::ESTIMATE, $quote->quoteType);
        $this->assertTrue($quote->isAvailable);
        $this->assertEquals('GBP', $quote->currency);
        $this->assertNotNull($quote->estimatedPickupMinutes);
        $this->assertEquals($route->durationMinutes, $quote->estimatedDurationMinutes);
        $this->assertEquals($route->distanceMiles, $quote->distanceMiles);
        $this->assertNotEmpty($quote->bookingUrl);
    }
}
