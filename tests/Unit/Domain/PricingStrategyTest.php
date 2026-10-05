<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Location\DTOs\Coordinates;
use App\Domain\Location\DTOs\Location;
use App\Domain\Location\DTOs\RouteInformation;
use App\Domain\Pricing\DTOs\PricingCalculationInput;
use App\Domain\Pricing\Services\PricingStrategyResolver;
use App\Domain\Pricing\Strategies\BoltPricingStrategy;
use App\Domain\Pricing\Strategies\StreetCarsPricingStrategy;
use App\Domain\Pricing\Strategies\UberPricingStrategy;
use App\Domain\Pricing\Strategies\VeezuPricingStrategy;
use App\Domain\Taxi\DTOs\TripRequest;
use App\Domain\Taxi\Enums\TaxiProvider;
use Tests\TestCase;

class PricingStrategyTest extends TestCase
{
    private PricingStrategyResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new PricingStrategyResolver([
            new UberPricingStrategy,
            new BoltPricingStrategy,
            new StreetCarsPricingStrategy,
            new VeezuPricingStrategy,
        ]);
    }

    public function test_resolver_supports_all_four_providers(): void
    {
        $this->assertTrue($this->resolver->has(TaxiProvider::UBER));
        $this->assertTrue($this->resolver->has(TaxiProvider::BOLT));
        $this->assertTrue($this->resolver->has(TaxiProvider::STREETCARS));
        $this->assertTrue($this->resolver->has(TaxiProvider::VEEZU));

        $this->assertInstanceOf(UberPricingStrategy::class, $this->resolver->resolve(TaxiProvider::UBER));
        $this->assertInstanceOf(BoltPricingStrategy::class, $this->resolver->resolve(TaxiProvider::BOLT));
        $this->assertInstanceOf(StreetCarsPricingStrategy::class, $this->resolver->resolve(TaxiProvider::STREETCARS));
        $this->assertInstanceOf(VeezuPricingStrategy::class, $this->resolver->resolve(TaxiProvider::VEEZU));
    }

    public function test_each_strategy_calculates_price_range_contract(): void
    {
        $route = RouteInformation::fromCalculatedValues(
            origin: new Location('A', 'Address A', new Coordinates(53.0, -2.0)),
            destination: new Location('B', 'Address B', new Coordinates(53.1, -2.1)),
            distanceMeters: 10000,
            durationSeconds: 900,
        );

        $trip = new TripRequest('A', 'B');

        foreach (TaxiProvider::cases() as $provider) {
            $strategy = $this->resolver->resolve($provider);
            $input = new PricingCalculationInput(
                provider: $provider,
                route: $route,
                trip: $trip,
            );

            $priceRange = $strategy->calculate($input);
            $this->assertEquals('GBP', $priceRange->currency);
            $this->assertGreaterThanOrEqual(0, $priceRange->min);
            $this->assertGreaterThanOrEqual(0, $priceRange->max);
        }
    }
}
