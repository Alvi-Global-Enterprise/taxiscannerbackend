<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Location\DTOs\Coordinates;
use App\Domain\Location\DTOs\Location;
use App\Domain\Location\DTOs\RouteInformation;
use App\Domain\Pricing\DTOs\PricingCalculationInput;
use App\Domain\Pricing\Strategies\BoltPricingStrategy;
use App\Domain\Pricing\Strategies\StreetCarsPricingStrategy;
use App\Domain\Pricing\Strategies\UberPricingStrategy;
use App\Domain\Pricing\Strategies\VeezuPricingStrategy;
use App\Domain\Taxi\DTOs\TripRequest;
use App\Domain\Taxi\Enums\TaxiProvider;
use App\Domain\Taxi\Models\Provider;
use App\Domain\Taxi\Models\ProviderPricingConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FareCalculationTest extends TestCase
{
    use RefreshDatabase;

    private UberPricingStrategy $uberStrategy;

    private BoltPricingStrategy $boltStrategy;

    private StreetCarsPricingStrategy $streetcarsStrategy;

    private VeezuPricingStrategy $veezuStrategy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uberStrategy = new UberPricingStrategy;
        $this->boltStrategy = new BoltPricingStrategy;
        $this->streetcarsStrategy = new StreetCarsPricingStrategy;
        $this->veezuStrategy = new VeezuPricingStrategy;
    }

    private function createRoute(
        float $distanceMiles,
        int $durationMinutes,
        string $originName = 'Standard Street',
        string $destinationName = 'High Street'
    ): RouteInformation {
        return RouteInformation::fromCalculatedValues(
            origin: new Location($originName, $originName.', Manchester, UK', new Coordinates(53.48, -2.24)),
            destination: new Location($destinationName, $destinationName.', Manchester, UK', new Coordinates(53.49, -2.25)),
            distanceMeters: (int) round($distanceMiles / 0.000621371),
            durationSeconds: $durationMinutes * 60,
        );
    }

    private function createConfig(array $overrides = []): ProviderPricingConfig
    {
        $config = new ProviderPricingConfig;
        $config->base_fare = $overrides['base_fare'] ?? 2.50;
        $config->per_mile_rate = $overrides['per_mile_rate'] ?? 1.50;
        $config->per_minute_rate = $overrides['per_minute_rate'] ?? 0.20;
        $config->minimum_fare = $overrides['minimum_fare'] ?? 5.00;
        $config->booking_fee = $overrides['booking_fee'] ?? 0.50;
        $config->airport_fee = $overrides['airport_fee'] ?? 4.00;
        $config->dynamic_multiplier = $overrides['dynamic_multiplier'] ?? 1.00;
        $config->currency = $overrides['currency'] ?? 'GBP';
        $config->config_data = $overrides['config_data'] ?? [
            'estimate_low_multiplier' => 0.95,
            'estimate_high_multiplier' => 1.05,
        ];

        return $config;
    }

    // 1. Base fare calculation
    public function test_1_base_fare_included_in_calculation(): void
    {
        $route = $this->createRoute(0.0, 0);
        $config = $this->createConfig([
            'base_fare' => 3.00,
            'minimum_fare' => 0.0,
            'booking_fee' => 0.0,
            'airport_fee' => 0.0,
        ]);

        $input = new PricingCalculationInput(
            provider: TaxiProvider::UBER,
            route: $route,
            trip: new TripRequest('A', 'B'),
            config: $config
        );

        $breakdown = $this->uberStrategy->calculateBreakdown($input);
        $this->assertEquals(3.00, $breakdown->baseFare);
        $this->assertEquals(3.00, $breakdown->total);
    }

    // 2. Distance charge
    public function test_2_distance_charge_calculated_correctly(): void
    {
        $route = $this->createRoute(10.0, 0); // 10 miles, 0 mins
        $config = $this->createConfig([
            'base_fare' => 0.0,
            'per_mile_rate' => 2.00,
            'minimum_fare' => 0.0,
            'booking_fee' => 0.0,
            'airport_fee' => 0.0,
        ]);

        $input = new PricingCalculationInput(
            provider: TaxiProvider::UBER,
            route: $route,
            trip: new TripRequest('A', 'B'),
            config: $config
        );

        $breakdown = $this->uberStrategy->calculateBreakdown($input);
        $this->assertEquals(20.00, $breakdown->distanceCharge);
        $this->assertEquals(20.00, $breakdown->total);
    }

    // 3. Time charge
    public function test_3_time_charge_calculated_correctly(): void
    {
        $route = $this->createRoute(0.0, 15); // 15 mins, 0 miles
        $config = $this->createConfig([
            'base_fare' => 0.0,
            'per_minute_rate' => 0.30,
            'minimum_fare' => 0.0,
            'booking_fee' => 0.0,
            'airport_fee' => 0.0,
        ]);

        $input = new PricingCalculationInput(
            provider: TaxiProvider::UBER,
            route: $route,
            trip: new TripRequest('A', 'B'),
            config: $config
        );

        $breakdown = $this->uberStrategy->calculateBreakdown($input);
        $this->assertEquals(4.50, $breakdown->durationCharge);
        $this->assertEquals(4.50, $breakdown->total);
    }

    // 4. Booking fee
    public function test_4_booking_fee_added_to_subtotal(): void
    {
        $route = $this->createRoute(0.0, 0);
        $config = $this->createConfig([
            'base_fare' => 2.00,
            'booking_fee' => 1.50,
            'minimum_fare' => 0.0,
            'airport_fee' => 0.0,
        ]);

        $input = new PricingCalculationInput(
            provider: TaxiProvider::UBER,
            route: $route,
            trip: new TripRequest('A', 'B'),
            config: $config
        );

        $breakdown = $this->uberStrategy->calculateBreakdown($input);
        $this->assertEquals(1.50, $breakdown->bookingFee);
        $this->assertEquals(3.50, $breakdown->total);
    }

    // 5. Airport fee
    public function test_5_airport_fee_applied_only_for_airport_locations(): void
    {
        $airportRoute = $this->createRoute(5.0, 10, 'Manchester Airport Terminal 1', 'Piccadilly');
        $regularRoute = $this->createRoute(5.0, 10, 'Victoria Station', 'Piccadilly');

        $config = $this->createConfig([
            'airport_fee' => 4.00,
            'minimum_fare' => 0.0,
        ]);

        // Airport trip
        $airportInput = new PricingCalculationInput(
            provider: TaxiProvider::UBER,
            route: $airportRoute,
            trip: new TripRequest('Manchester Airport', 'Piccadilly'),
            config: $config
        );
        $airportBreakdown = $this->uberStrategy->calculateBreakdown($airportInput);
        $this->assertEquals(4.00, $airportBreakdown->airportFee);

        // Regular trip (no airport)
        $regularInput = new PricingCalculationInput(
            provider: TaxiProvider::UBER,
            route: $regularRoute,
            trip: new TripRequest('Victoria Station', 'Piccadilly'),
            config: $config
        );
        $regularBreakdown = $this->uberStrategy->calculateBreakdown($regularInput);
        $this->assertEquals(0.0, $regularBreakdown->airportFee);
    }

    // 6. Dynamic multiplier
    public function test_6_dynamic_multiplier_scales_fare(): void
    {
        $route = $this->createRoute(5.0, 10);
        $config1x = $this->createConfig(['dynamic_multiplier' => 1.00, 'minimum_fare' => 0.0]);
        $config15x = $this->createConfig(['dynamic_multiplier' => 1.50, 'minimum_fare' => 0.0]);

        $input1x = new PricingCalculationInput(
            provider: TaxiProvider::UBER,
            route: $route,
            trip: new TripRequest('A', 'B'),
            config: $config1x
        );
        $input15x = new PricingCalculationInput(
            provider: TaxiProvider::UBER,
            route: $route,
            trip: new TripRequest('A', 'B'),
            config: $config15x
        );

        $breakdown1x = $this->uberStrategy->calculateBreakdown($input1x);
        $breakdown15x = $this->uberStrategy->calculateBreakdown($input15x);

        $this->assertEquals(round($breakdown1x->subtotal * 1.5, 2), $breakdown15x->total);
    }

    // 7. Minimum fare
    public function test_7_minimum_fare_enforced_when_calculated_is_lower(): void
    {
        $route = $this->createRoute(0.5, 1); // very short trip: ~£3 total
        $config = $this->createConfig([
            'base_fare' => 1.00,
            'per_mile_rate' => 1.00,
            'per_minute_rate' => 0.10,
            'minimum_fare' => 8.00, // higher than subtotal
        ]);

        $input = new PricingCalculationInput(
            provider: TaxiProvider::UBER,
            route: $route,
            trip: new TripRequest('A', 'B'),
            config: $config
        );

        $breakdown = $this->uberStrategy->calculateBreakdown($input);
        $this->assertEquals(8.00, $breakdown->total);
        $this->assertTrue($breakdown->extraItems['minimum_fare_applied']);
    }

    // 8. Zero distance
    public function test_8_zero_distance_handles_gracefully(): void
    {
        $route = $this->createRoute(0.0, 5);
        $config = $this->createConfig(['minimum_fare' => 0.0]);

        $input = new PricingCalculationInput(
            provider: TaxiProvider::UBER,
            route: $route,
            trip: new TripRequest('A', 'B'),
            config: $config
        );

        $breakdown = $this->uberStrategy->calculateBreakdown($input);
        $this->assertEquals(0.0, $breakdown->distanceCharge);
        $this->assertGreaterThan(0.0, $breakdown->total);
    }

    // 9. Zero duration
    public function test_9_zero_duration_handles_gracefully(): void
    {
        $route = $this->createRoute(5.0, 0);
        $config = $this->createConfig(['minimum_fare' => 0.0]);

        $input = new PricingCalculationInput(
            provider: TaxiProvider::UBER,
            route: $route,
            trip: new TripRequest('A', 'B'),
            config: $config
        );

        $breakdown = $this->uberStrategy->calculateBreakdown($input);
        $this->assertEquals(0.0, $breakdown->durationCharge);
        $this->assertGreaterThan(0.0, $breakdown->total);
    }

    // 10. Long-distance trip
    public function test_10_long_distance_trip_scales_proportionally(): void
    {
        $route = $this->createRoute(150.0, 180); // 150 miles, 3 hours
        $config = $this->createConfig();

        $input = new PricingCalculationInput(
            provider: TaxiProvider::UBER,
            route: $route,
            trip: new TripRequest('Manchester', 'London'),
            config: $config
        );

        $breakdown = $this->uberStrategy->calculateBreakdown($input);
        $this->assertGreaterThan(200.00, $breakdown->total);
        $this->assertEquals(225.00, $breakdown->distanceCharge);
        $this->assertEquals(36.00, $breakdown->durationCharge);
    }

    // 11. Each provider strategy
    public function test_11_all_four_provider_strategies_calculate_independently(): void
    {
        $route = $this->createRoute(8.0, 20);
        $trip = new TripRequest('A', 'B');

        $uberConfig = $this->createConfig(['base_fare' => 2.50, 'per_mile_rate' => 1.40]);
        $boltConfig = $this->createConfig(['base_fare' => 2.20, 'per_mile_rate' => 1.35]);
        $streetcarsConfig = $this->createConfig(['base_fare' => 3.00, 'per_mile_rate' => 1.60]);
        $veezuConfig = $this->createConfig(['base_fare' => 2.80, 'per_mile_rate' => 1.50]);

        $uberQuote = $this->uberStrategy->calculate(new PricingCalculationInput(TaxiProvider::UBER, $route, $trip, $uberConfig));
        $boltQuote = $this->boltStrategy->calculate(new PricingCalculationInput(TaxiProvider::BOLT, $route, $trip, $boltConfig));
        $streetcarsQuote = $this->streetcarsStrategy->calculate(new PricingCalculationInput(TaxiProvider::STREETCARS, $route, $trip, $streetcarsConfig));
        $veezuQuote = $this->veezuStrategy->calculate(new PricingCalculationInput(TaxiProvider::VEEZU, $route, $trip, $veezuConfig));

        $this->assertGreaterThan(0.0, $uberQuote->min);
        $this->assertGreaterThan(0.0, $boltQuote->min);
        $this->assertGreaterThan(0.0, $streetcarsQuote->min);
        $this->assertGreaterThan(0.0, $veezuQuote->min);
    }

    // 12. Provider failure isolation
    public function test_12_missing_config_falls_back_gracefully_without_crashing(): void
    {
        $route = $this->createRoute(5.0, 10);
        $trip = new TripRequest('A', 'B');

        // Config is completely null
        $input = new PricingCalculationInput(TaxiProvider::UBER, $route, $trip, null);
        $quote = $this->uberStrategy->calculate($input);

        $this->assertNotNull($quote);
        $this->assertEquals('GBP', $quote->currency);
        $this->assertEquals(0.0, $quote->min);
    }

    // 13. Price range calculation
    public function test_13_price_range_calculated_deterministically(): void
    {
        $route = $this->createRoute(10.0, 15);
        $config = $this->createConfig([
            'config_data' => [
                'estimate_low_multiplier' => 0.90,
                'estimate_high_multiplier' => 1.10,
            ],
            'minimum_fare' => 0.0,
        ]);

        $input = new PricingCalculationInput(TaxiProvider::UBER, $route, $trip = new TripRequest('A', 'B'), $config);
        $breakdown = $this->uberStrategy->calculateBreakdown($input);
        $range = $this->uberStrategy->calculate($input);

        $expectedMin = round($breakdown->total * 0.90, 2);
        $expectedMax = round($breakdown->total * 1.10, 2);

        $this->assertEquals($expectedMin, $range->min);
        $this->assertEquals($expectedMax, $range->max);
    }

    // 14. Currency handling
    public function test_14_currency_preserved_from_configuration(): void
    {
        $route = $this->createRoute(5.0, 10);
        $config = $this->createConfig(['currency' => 'EUR']);

        $input = new PricingCalculationInput(TaxiProvider::UBER, $route, new TripRequest('A', 'B'), $config);
        $range = $this->uberStrategy->calculate($input);

        $this->assertEquals('EUR', $range->currency);
    }

    // 15. Database pricing configuration
    public function test_15_reads_pricing_from_database_models(): void
    {
        $this->seed();

        $provider = Provider::where('slug', 'uber')->first();
        $this->assertNotNull($provider);

        $config = $provider->activePricingConfig;
        $this->assertNotNull($config);
        $this->assertEquals(2.50, $config->base_fare);
        $this->assertEquals(1.40, $config->per_mile_rate);
        $this->assertEquals(0.15, $config->per_minute_rate);
        $this->assertEquals(4.50, $config->minimum_fare);
    }
}
