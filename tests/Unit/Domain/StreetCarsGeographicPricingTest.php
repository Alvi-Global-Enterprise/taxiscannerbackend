<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Location\DTOs\Coordinates;
use App\Domain\Location\DTOs\Location;
use App\Domain\Location\DTOs\RouteInformation;
use App\Domain\Pricing\DTOs\PricingCalculationInput;
use App\Domain\Pricing\Services\StreetCarsGeographicAdjustmentService;
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

class StreetCarsGeographicPricingTest extends TestCase
{
    use RefreshDatabase;

    private ProviderPricingConfig $streetcarsConfig;

    private StreetCarsGeographicAdjustmentService $geoService;

    private StreetCarsPricingStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        $provider = Provider::where('slug', 'streetcars')->firstOrFail();
        $this->streetcarsConfig = ProviderPricingConfig::where('provider_id', $provider->id)
            ->where('is_active', true)
            ->firstOrFail();

        $this->streetcarsConfig->update([
            'config_data' => [
                'estimate_low_multiplier' => 0.95,
                'estimate_high_multiplier' => 1.05,
                'category_calibration_multipliers' => [
                    'airport_to_city' => 1.0440,
                    'city_medium' => 0.6971,
                    'city_short' => 0.7040,
                    'city_to_airport' => 1.0915,
                ],
            ],
        ]);
        $this->streetcarsConfig->refresh();

        $this->geoService = new StreetCarsGeographicAdjustmentService;
        $this->strategy = new StreetCarsPricingStrategy($this->geoService);
    }

    private function createInput(
        string $pickupQuery,
        string $dropoffQuery,
        float $distanceMiles,
        int $durationMinutes,
        Coordinates $dropoffCoords,
        ?string $dropoffFormatted = null,
        ?string $dropoffPostcode = null,
    ): PricingCalculationInput {
        $dropoffFormatted = $dropoffFormatted ?? $dropoffQuery;
        $originCoords = new Coordinates(53.36195, -2.27273); // Manchester Airport

        $origin = new Location(
            query: $pickupQuery,
            formattedAddress: 'Manchester Airport (MAN), Ringway, Manchester, M90 1QX, UK',
            coordinates: $originCoords,
        );

        $destination = new Location(
            query: $dropoffQuery,
            formattedAddress: $dropoffFormatted,
            coordinates: $dropoffCoords,
            postcode: $dropoffPostcode,
        );

        $route = new RouteInformation(
            origin: $origin,
            destination: $destination,
            distanceMeters: (int) round($distanceMiles * 1609.34),
            distanceMiles: $distanceMiles,
            durationSeconds: $durationMinutes * 60,
            durationMinutes: $durationMinutes,
        );

        return new PricingCalculationInput(
            provider: TaxiProvider::STREETCARS,
            route: $route,
            trip: new TripRequest($pickupQuery, $dropoffQuery),
            config: $this->streetcarsConfig,
        );
    }

    public function test_disabled_by_default_in_production(): void
    {
        $this->assertFalse(config('taxiscanner.streetcars.geographic_calibration.enabled'));
        $this->assertFalse($this->geoService->isEnabled());

        $input = $this->createInput(
            pickupQuery: 'Manchester Airport (MAN)',
            dropoffQuery: 'Handforth, Wilmslow, UK',
            distanceMiles: 3.5,
            durationMinutes: 10,
            dropoffCoords: new Coordinates(53.345, -2.215),
        );

        $breakdown = $this->strategy->calculateBreakdown($input);

        $this->assertFalse($breakdown->extraItems['geographic_calibration_applied'] ?? false);
        $this->assertEquals(1.0000, $breakdown->calibrationMultiplier);
    }

    public function test_enabled_geographic_calibration_activates_adjustment(): void
    {
        config(['taxiscanner.streetcars.geographic_calibration.enabled' => true]);

        $input = $this->createInput(
            pickupQuery: 'Manchester Airport (MAN)',
            dropoffQuery: 'Handforth, Wilmslow, UK',
            distanceMiles: 3.5,
            durationMinutes: 10,
            dropoffCoords: new Coordinates(53.345, -2.215),
        );

        $breakdown = $this->strategy->calculateBreakdown($input);

        $this->assertTrue($breakdown->extraItems['geographic_calibration_applied']);
        $this->assertEquals('handforth_wilmslow', $breakdown->extraItems['geographic_zone']);
        $this->assertEquals(22.00, $breakdown->total);
    }

    public function test_actual_wilmslow_matches_handforth_wilmslow(): void
    {
        config(['taxiscanner.streetcars.geographic_calibration.enabled' => true]);

        $input = $this->createInput(
            pickupQuery: 'Manchester Airport (MAN)',
            dropoffQuery: 'Water Lane, Wilmslow, Cheshire, UK',
            distanceMiles: 6.5,
            durationMinutes: 16,
            dropoffCoords: new Coordinates(53.326, -2.235),
            dropoffPostcode: 'SK9 5AH',
        );

        $breakdown = $this->strategy->calculateBreakdown($input);

        $this->assertTrue($breakdown->extraItems['geographic_calibration_applied']);
        $this->assertEquals('handforth_wilmslow', $breakdown->extraItems['geographic_zone']);
        $this->assertEquals(1.1721, $breakdown->extraItems['geographic_multiplier']);
        $this->assertGreaterThanOrEqual(22.00, $breakdown->total);
    }

    public function test_actual_handforth_matches_handforth_wilmslow(): void
    {
        config(['taxiscanner.streetcars.geographic_calibration.enabled' => true]);

        $input = $this->createInput(
            pickupQuery: 'Manchester Airport (MAN)',
            dropoffQuery: 'The Paddock, Handforth, Wilmslow, UK',
            distanceMiles: 3.5,
            durationMinutes: 10,
            dropoffCoords: new Coordinates(53.354, -2.214),
            dropoffPostcode: 'SK9 3AB',
        );

        $breakdown = $this->strategy->calculateBreakdown($input);

        $this->assertTrue($breakdown->extraItems['geographic_calibration_applied']);
        $this->assertEquals('handforth_wilmslow', $breakdown->extraItems['geographic_zone']);
        $this->assertEquals(22.00, $breakdown->total);
    }

    public function test_altrincham_matches_altrincham_zone(): void
    {
        config(['taxiscanner.streetcars.geographic_calibration.enabled' => true]);

        $input = $this->createInput(
            pickupQuery: 'Manchester Airport (MAN)',
            dropoffQuery: 'George Street, Altrincham, UK',
            distanceMiles: 5.9,
            durationMinutes: 14,
            dropoffCoords: new Coordinates(53.387, -2.355),
            dropoffPostcode: 'WA14 1EP',
        );

        $breakdown = $this->strategy->calculateBreakdown($input);

        $this->assertTrue($breakdown->extraItems['geographic_calibration_applied']);
        $this->assertEquals('altrincham', $breakdown->extraItems['geographic_zone']);
        $this->assertEquals(1.0945, $breakdown->extraItems['geographic_multiplier']);
    }

    public function test_sale_matches_sale_zone(): void
    {
        config(['taxiscanner.streetcars.geographic_calibration.enabled' => true]);

        $input = $this->createInput(
            pickupQuery: 'Manchester Airport (MAN)',
            dropoffQuery: 'School Road, Sale, Greater Manchester, UK',
            distanceMiles: 7.9,
            durationMinutes: 16,
            dropoffCoords: new Coordinates(53.424, -2.324),
            dropoffPostcode: 'M33 7WZ',
        );

        $breakdown = $this->strategy->calculateBreakdown($input);

        $this->assertTrue($breakdown->extraItems['geographic_calibration_applied']);
        $this->assertEquals('sale', $breakdown->extraItems['geographic_zone']);
        $this->assertEquals(1.0945, $breakdown->extraItems['geographic_multiplier']);
    }

    public function test_stockport_town_centre_matches_stockport_zone(): void
    {
        config(['taxiscanner.streetcars.geographic_calibration.enabled' => true]);

        $input = $this->createInput(
            pickupQuery: 'Manchester Airport (MAN)',
            dropoffQuery: 'Merseyway Shopping Centre, Stockport, UK',
            distanceMiles: 8.5,
            durationMinutes: 19,
            dropoffCoords: new Coordinates(53.411, -2.160),
            dropoffPostcode: 'SK1 1QE',
        );

        $breakdown = $this->strategy->calculateBreakdown($input);

        $this->assertTrue($breakdown->extraItems['geographic_calibration_applied']);
        $this->assertEquals('stockport', $breakdown->extraItems['geographic_zone']);
        $this->assertEquals(1.2580, $breakdown->extraItems['geographic_multiplier']);
    }

    public function test_cheadle_matches_cheadle_zone(): void
    {
        config(['taxiscanner.streetcars.geographic_calibration.enabled' => true]);

        $input = $this->createInput(
            pickupQuery: 'Manchester Airport (MAN)',
            dropoffQuery: 'High Street, Cheadle, Greater Manchester, UK',
            distanceMiles: 6.6,
            durationMinutes: 15,
            dropoffCoords: new Coordinates(53.393, -2.214),
            dropoffPostcode: 'SK8 1AX',
        );

        $breakdown = $this->strategy->calculateBreakdown($input);

        $this->assertTrue($breakdown->extraItems['geographic_calibration_applied']);
        $this->assertEquals('cheadle', $breakdown->extraItems['geographic_zone']);
        $this->assertEquals(0.8698, $breakdown->extraItems['geographic_multiplier']);
    }

    public function test_wythenshawe_falls_back_safely(): void
    {
        config(['taxiscanner.streetcars.geographic_calibration.enabled' => true]);

        $input = $this->createInput(
            pickupQuery: 'Manchester Airport (MAN)',
            dropoffQuery: 'Poundswick Lane, Wythenshawe, Manchester, UK',
            distanceMiles: 2.2,
            durationMinutes: 10,
            dropoffCoords: new Coordinates(53.381, -2.262),
            dropoffPostcode: 'M22 9TA',
        );

        $breakdown = $this->strategy->calculateBreakdown($input);

        $this->assertFalse($breakdown->extraItems['geographic_calibration_applied'] ?? false);
        $this->assertEquals(1.0000, $breakdown->calibrationMultiplier);
    }

    public function test_airport_perimeter_falls_back_safely(): void
    {
        config(['taxiscanner.streetcars.geographic_calibration.enabled' => true]);

        $input = $this->createInput(
            pickupQuery: 'Manchester Airport (MAN)',
            dropoffQuery: 'Atlanta Way, Ringway, Manchester Airport, UK',
            distanceMiles: 1.5,
            durationMinutes: 5,
            dropoffCoords: new Coordinates(53.365, -2.268),
            dropoffPostcode: 'M90 4ES',
        );

        $breakdown = $this->strategy->calculateBreakdown($input);

        $this->assertFalse($breakdown->extraItems['geographic_calibration_applied'] ?? false);
        $this->assertEquals(1.0000, $breakdown->calibrationMultiplier);
    }

    public function test_didsbury_matches_didsbury_zone(): void
    {
        config(['taxiscanner.streetcars.geographic_calibration.enabled' => true]);

        // Wilmslow Road in Didsbury must match didsbury zone and NOT handforth_wilmslow
        $input = $this->createInput(
            pickupQuery: 'Manchester Airport (MAN)',
            dropoffQuery: 'Wilmslow Road, Didsbury, Manchester, UK',
            distanceMiles: 6.8,
            durationMinutes: 17,
            dropoffCoords: new Coordinates(53.417, -2.231),
            dropoffPostcode: 'M20 2RN',
        );

        $breakdown = $this->strategy->calculateBreakdown($input);

        $this->assertTrue($breakdown->extraItems['geographic_calibration_applied'] ?? false);
        $this->assertEquals('didsbury', $breakdown->extraItems['geographic_zone']);
        $this->assertEquals(1.1518, $breakdown->extraItems['geographic_multiplier']);
    }

    public function test_withington_falls_back_safely(): void
    {
        config(['taxiscanner.streetcars.geographic_calibration.enabled' => true]);

        $input = $this->createInput(
            pickupQuery: 'Manchester Airport (MAN)',
            dropoffQuery: 'Wilmslow Road, Withington, Manchester, UK',
            distanceMiles: 7.8,
            durationMinutes: 20,
            dropoffCoords: new Coordinates(53.432, -2.228),
            dropoffPostcode: 'M20 4BN',
        );

        $breakdown = $this->strategy->calculateBreakdown($input);

        $this->assertFalse($breakdown->extraItems['geographic_calibration_applied'] ?? false);
        $this->assertEquals(1.0000, $breakdown->calibrationMultiplier);
    }

    public function test_fallowfield_falls_back_safely(): void
    {
        config(['taxiscanner.streetcars.geographic_calibration.enabled' => true]);

        $input = $this->createInput(
            pickupQuery: 'Manchester Airport (MAN)',
            dropoffQuery: 'Wilmslow Road, Fallowfield, Manchester, UK',
            distanceMiles: 8.5,
            durationMinutes: 22,
            dropoffCoords: new Coordinates(53.444, -2.223),
            dropoffPostcode: 'M14 6XU',
        );

        $breakdown = $this->strategy->calculateBreakdown($input);

        $this->assertFalse($breakdown->extraItems['geographic_calibration_applied'] ?? false);
        $this->assertEquals(1.0000, $breakdown->calibrationMultiplier);
    }

    public function test_rusholme_falls_back_safely(): void
    {
        config(['taxiscanner.streetcars.geographic_calibration.enabled' => true]);

        $input = $this->createInput(
            pickupQuery: 'Manchester Airport (MAN)',
            dropoffQuery: 'Wilmslow Road, Rusholme, Manchester, UK',
            distanceMiles: 9.0,
            durationMinutes: 24,
            dropoffCoords: new Coordinates(53.456, -2.221),
            dropoffPostcode: 'M14 5TP',
        );

        $breakdown = $this->strategy->calculateBreakdown($input);

        $this->assertFalse($breakdown->extraItems['geographic_calibration_applied'] ?? false);
        $this->assertEquals(1.0000, $breakdown->calibrationMultiplier);
    }

    public function test_bramhall_does_not_match_stockport_merely_because_of_borough_name(): void
    {
        config(['taxiscanner.streetcars.geographic_calibration.enabled' => true]);

        $input = $this->createInput(
            pickupQuery: 'Manchester Airport (MAN)',
            dropoffQuery: 'Bramhall Lane South, Bramhall, Stockport, UK',
            distanceMiles: 7.5,
            durationMinutes: 18,
            dropoffCoords: new Coordinates(53.360, -2.165),
            dropoffPostcode: 'SK7 1AB',
        );

        $breakdown = $this->strategy->calculateBreakdown($input);

        $this->assertFalse($breakdown->extraItems['geographic_calibration_applied'] ?? false);
        $this->assertEquals(1.0000, $breakdown->calibrationMultiplier);
    }

    public function test_poynton_does_not_match_stockport_merely_because_of_borough_name(): void
    {
        config(['taxiscanner.streetcars.geographic_calibration.enabled' => true]);

        $input = $this->createInput(
            pickupQuery: 'Manchester Airport (MAN)',
            dropoffQuery: 'Park Lane, Poynton, Stockport, UK',
            distanceMiles: 9.0,
            durationMinutes: 22,
            dropoffCoords: new Coordinates(53.348, -2.125),
            dropoffPostcode: 'SK12 1AB',
        );

        $breakdown = $this->strategy->calculateBreakdown($input);

        $this->assertFalse($breakdown->extraItems['geographic_calibration_applied'] ?? false);
        $this->assertEquals(1.0000, $breakdown->calibrationMultiplier);
    }

    public function test_partington_falls_back_safely(): void
    {
        config(['taxiscanner.streetcars.geographic_calibration.enabled' => true]);

        $input = $this->createInput(
            pickupQuery: 'Manchester Airport (MAN)',
            dropoffQuery: 'Partington, Greater Manchester, UK',
            distanceMiles: 12.9,
            durationMinutes: 24,
            dropoffCoords: new Coordinates(53.418, -2.430),
            dropoffPostcode: 'M31 4AB',
        );

        $breakdown = $this->strategy->calculateBreakdown($input);

        $this->assertFalse($breakdown->extraItems['geographic_calibration_applied'] ?? false);
        $this->assertEquals(1.0000, $breakdown->calibrationMultiplier);
    }

    public function test_carrington_falls_back_safely(): void
    {
        config(['taxiscanner.streetcars.geographic_calibration.enabled' => true]);

        $input = $this->createInput(
            pickupQuery: 'Manchester Airport (MAN)',
            dropoffQuery: 'Manchester Road, Carrington, Manchester, UK',
            distanceMiles: 11.5,
            durationMinutes: 22,
            dropoffCoords: new Coordinates(53.435, -2.400),
            dropoffPostcode: 'M31 4XN',
        );

        $breakdown = $this->strategy->calculateBreakdown($input);

        $this->assertFalse($breakdown->extraItems['geographic_calibration_applied'] ?? false);
        $this->assertEquals(1.0000, $breakdown->calibrationMultiplier);
    }

    public function test_destination_outside_all_zones_falls_back_safely(): void
    {
        config(['taxiscanner.streetcars.geographic_calibration.enabled' => true]);

        // Bolton is > 18 miles north of Manchester Airport and matches no southern zone
        $input = $this->createInput(
            pickupQuery: 'Manchester Airport (MAN)',
            dropoffQuery: 'Bolton Town Centre, Bolton, Greater Manchester, UK',
            distanceMiles: 22.0,
            durationMinutes: 35,
            dropoffCoords: new Coordinates(53.578, -2.429),
            dropoffPostcode: 'BL1 1RU',
        );

        $breakdown = $this->strategy->calculateBreakdown($input);

        $this->assertFalse($breakdown->extraItems['geographic_calibration_applied'] ?? false);
        $this->assertEquals(1.0000, $breakdown->calibrationMultiplier);
    }

    public function test_coordinate_only_proximity_without_keyword_or_postcode_caps_confidence_at_most_055_and_falls_back(): void
    {
        config(['taxiscanner.streetcars.geographic_calibration.enabled' => true]);

        // Place destination right at the center of Knutsford (53.3040, -2.3750)
        // but with NO matching keywords and an unrelated postcode
        $match = $this->geoService->matchZone(
            address: 'Some Random Farm Lane',
            query: 'Some Random Place',
            coordinates: new Coordinates(53.3040, -2.3750),
            postcode: 'CW9 8XX',
        );

        // Coordinate-only confidence is capped at <= 0.55, which is strictly below 0.60 threshold
        $this->assertNull($match);

        // Also test directly via strategy calculateBreakdown
        $input = $this->createInput(
            pickupQuery: 'Manchester Airport (MAN)',
            dropoffQuery: 'Some Random Place, UK',
            distanceMiles: 11.0,
            durationMinutes: 20,
            dropoffCoords: new Coordinates(53.3040, -2.3750),
            dropoffFormatted: 'Some Random Farm Lane, UK',
            dropoffPostcode: 'CW9 8XX',
        );

        $breakdown = $this->strategy->calculateBreakdown($input);

        $this->assertFalse($breakdown->extraItems['geographic_calibration_applied'] ?? false);
        $this->assertEquals(1.0000, $breakdown->calibrationMultiplier);
    }

    public function test_wilmslow_road_with_sk9_postcode_qualifies_for_wilmslow_zone(): void
    {
        config(['taxiscanner.streetcars.geographic_calibration.enabled' => true]);

        // When Wilmslow Road is accompanied by SK9 postcode in Handforth/Wilmslow
        $input = $this->createInput(
            pickupQuery: 'Manchester Airport (MAN)',
            dropoffQuery: 'Wilmslow Road, Handforth, Wilmslow, UK',
            distanceMiles: 3.8,
            durationMinutes: 10,
            dropoffCoords: new Coordinates(53.345, -2.215),
            dropoffFormatted: 'Wilmslow Road, Handforth, Wilmslow, Cheshire, SK9 3ET, UK',
            dropoffPostcode: 'SK9 3ET',
        );

        $breakdown = $this->strategy->calculateBreakdown($input);

        $this->assertTrue($breakdown->extraItems['geographic_calibration_applied']);
        $this->assertEquals('handforth_wilmslow', $breakdown->extraItems['geographic_zone']);
        $this->assertEquals(22.00, $breakdown->total);
    }

    public function test_wilmslow_road_without_sk9_or_context_does_not_qualify(): void
    {
        config(['taxiscanner.streetcars.geographic_calibration.enabled' => true]);

        // "Wilmslow Road" alone without SK9 postcode or Wilmslow town context
        $input = $this->createInput(
            pickupQuery: 'Manchester Airport (MAN)',
            dropoffQuery: 'Wilmslow Road, Manchester, UK',
            distanceMiles: 7.0,
            durationMinutes: 18,
            dropoffCoords: new Coordinates(53.420, -2.230),
            dropoffFormatted: 'Wilmslow Road, Manchester, M20 2AB, UK',
            dropoffPostcode: 'M20 2AB',
        );

        $breakdown = $this->strategy->calculateBreakdown($input);

        $this->assertFalse($breakdown->extraItems['geographic_calibration_applied'] ?? false);
        $this->assertEquals(1.0000, $breakdown->calibrationMultiplier);
    }

    public function test_overlapping_zone_precedence_is_deterministic(): void
    {
        config(['taxiscanner.streetcars.geographic_calibration.enabled' => true]);

        // Cheadle has priority 75, surrounding zones have lower priority.
        $input = $this->createInput(
            pickupQuery: 'Manchester Airport (MAN)',
            dropoffQuery: 'Cheadle Hulme, Cheadle, Greater Manchester, UK',
            distanceMiles: 6.0,
            durationMinutes: 14,
            dropoffCoords: new Coordinates(53.376, -2.188),
            dropoffPostcode: 'SK8 7AA',
        );

        $breakdown = $this->strategy->calculateBreakdown($input);

        $this->assertTrue($breakdown->extraItems['geographic_calibration_applied']);
        $this->assertEquals('cheadle', $breakdown->extraItems['geographic_zone']);
        $this->assertEquals(75, $breakdown->extraItems['geographic_precedence']);
    }

    public function test_airport_to_city_is_unaffected(): void
    {
        config(['taxiscanner.streetcars.geographic_calibration.enabled' => true]);

        $input = $this->createInput(
            pickupQuery: 'Manchester Airport (MAN)',
            dropoffQuery: 'Manchester Piccadilly Station, Manchester, M60 7RA',
            distanceMiles: 9.5,
            durationMinutes: 25,
            dropoffCoords: new Coordinates(53.477, -2.230),
        );

        $breakdown = $this->strategy->calculateBreakdown($input);

        $this->assertFalse($breakdown->extraItems['geographic_calibration_applied'] ?? false);
        $this->assertEquals('airport_to_city', $breakdown->extraItems['calibration_category']);
        $this->assertEquals(1.0440, $breakdown->calibrationMultiplier);
    }

    public function test_city_medium_is_unaffected(): void
    {
        config(['taxiscanner.streetcars.geographic_calibration.enabled' => true]);

        $origin = new Location('Piccadilly', 'Manchester Piccadilly', new Coordinates(53.477, -2.230));
        $destination = new Location('Salford Quays', 'Salford Quays, Manchester', new Coordinates(53.471, -2.285));
        $route = new RouteInformation($origin, $destination, 8000, 5.0, 900, 15);

        $input = new PricingCalculationInput(
            provider: TaxiProvider::STREETCARS,
            route: $route,
            trip: new TripRequest('Manchester Piccadilly', 'Salford Quays'),
            config: $this->streetcarsConfig,
        );

        $breakdown = $this->strategy->calculateBreakdown($input);

        $this->assertFalse($breakdown->extraItems['geographic_calibration_applied'] ?? false);
        $this->assertEquals('city_medium', $breakdown->extraItems['calibration_category']);
        $this->assertEquals(0.6971, $breakdown->calibrationMultiplier);
    }

    public function test_city_short_is_unaffected(): void
    {
        config(['taxiscanner.streetcars.geographic_calibration.enabled' => true]);

        $origin = new Location('Piccadilly', 'Manchester Piccadilly', new Coordinates(53.477, -2.230));
        $destination = new Location('Arndale', 'Manchester Arndale', new Coordinates(53.483, -2.242));
        $route = new RouteInformation($origin, $destination, 1500, 1.0, 300, 5);

        $input = new PricingCalculationInput(
            provider: TaxiProvider::STREETCARS,
            route: $route,
            trip: new TripRequest('Manchester Piccadilly', 'Manchester Arndale'),
            config: $this->streetcarsConfig,
        );

        $breakdown = $this->strategy->calculateBreakdown($input);

        $this->assertFalse($breakdown->extraItems['geographic_calibration_applied'] ?? false);
        $this->assertEquals('city_short', $breakdown->extraItems['calibration_category']);
        $this->assertEquals(0.7040, $breakdown->calibrationMultiplier);
    }

    public function test_city_to_airport_is_unaffected(): void
    {
        config(['taxiscanner.streetcars.geographic_calibration.enabled' => true]);

        $origin = new Location('Piccadilly', 'Manchester Piccadilly Station', new Coordinates(53.477, -2.230));
        $destination = new Location('Airport', 'Manchester Airport (MAN)', new Coordinates(53.361, -2.272));
        $route = new RouteInformation($origin, $destination, 15000, 9.4, 1500, 25);

        $input = new PricingCalculationInput(
            provider: TaxiProvider::STREETCARS,
            route: $route,
            trip: new TripRequest('Manchester Piccadilly Station', 'Manchester Airport (MAN)'),
            config: $this->streetcarsConfig,
        );

        $breakdown = $this->strategy->calculateBreakdown($input);

        $this->assertFalse($breakdown->extraItems['geographic_calibration_applied'] ?? false);
        $this->assertEquals('city_to_airport', $breakdown->extraItems['calibration_category']);
        $this->assertEquals(1.0915, $breakdown->calibrationMultiplier);
    }

    public function test_city_long_boundary_and_cheshire_oaks_pricing(): void
    {
        config(['taxiscanner.streetcars.geographic_calibration.enabled' => true]);

        // 1. Boundary: 4.0 miles non-airport -> city_short (multiplier 0.7040)
        $origin40 = new Location('Piccadilly', 'Manchester Piccadilly Station', new Coordinates(53.477, -2.230));
        $dest40 = new Location('Salford', 'Salford Precinct', new Coordinates(53.485, -2.275));
        $route40 = new RouteInformation($origin40, $dest40, 6437, 4.0, 600, 10);
        $input40 = new PricingCalculationInput(
            provider: TaxiProvider::STREETCARS,
            route: $route40,
            trip: new TripRequest('Manchester Piccadilly Station', 'Salford Precinct'),
            config: $this->streetcarsConfig,
        );
        $breakdown40 = $this->strategy->calculateBreakdown($input40);
        $this->assertEquals('city_short', $breakdown40->extraItems['trip_category']);
        $this->assertEquals('city_short', $breakdown40->extraItems['calibration_category']);
        $this->assertEquals(0.7040, $breakdown40->calibrationMultiplier);

        // 2. Boundary: 4.01 miles non-airport -> city_medium (multiplier 0.6971)
        $route401 = new RouteInformation($origin40, $dest40, 6453, 4.01, 600, 10);
        $input401 = new PricingCalculationInput(
            provider: TaxiProvider::STREETCARS,
            route: $route401,
            trip: new TripRequest('Manchester Piccadilly Station', 'Salford Precinct'),
            config: $this->streetcarsConfig,
        );
        $breakdown401 = $this->strategy->calculateBreakdown($input401);
        $this->assertEquals('city_medium', $breakdown401->extraItems['trip_category']);
        $this->assertEquals('city_medium', $breakdown401->extraItems['calibration_category']);
        $this->assertEquals(0.6971, $breakdown401->calibrationMultiplier);

        // 3. Boundary: 15.0 miles non-airport -> city_medium (multiplier 0.6971)
        $dest150 = new Location('Rochdale', 'Rochdale Town Centre', new Coordinates(53.617, -2.155));
        $route150 = new RouteInformation($origin40, $dest150, 24140, 15.0, 1800, 30);
        $input150 = new PricingCalculationInput(
            provider: TaxiProvider::STREETCARS,
            route: $route150,
            trip: new TripRequest('Manchester Piccadilly Station', 'Rochdale Town Centre'),
            config: $this->streetcarsConfig,
        );
        $breakdown150 = $this->strategy->calculateBreakdown($input150);
        $this->assertEquals('city_medium', $breakdown150->extraItems['trip_category']);
        $this->assertEquals('city_medium', $breakdown150->extraItems['calibration_category']);
        $this->assertEquals(0.6971, $breakdown150->calibrationMultiplier);

        // 4. Boundary: 15.01 miles non-airport -> city_long (multiplier 1.0000)
        $route1501 = new RouteInformation($origin40, $dest150, 24156, 15.01, 1800, 30);
        $input1501 = new PricingCalculationInput(
            provider: TaxiProvider::STREETCARS,
            route: $route1501,
            trip: new TripRequest('Manchester Piccadilly Station', 'Littleborough'),
            config: $this->streetcarsConfig,
        );
        $breakdown1501 = $this->strategy->calculateBreakdown($input1501);
        $this->assertEquals('city_long', $breakdown1501->extraItems['trip_category']);
        $this->assertEquals(1.0000, $breakdown1501->calibrationMultiplier);
        $this->assertFalse($breakdown1501->extraItems['geographic_calibration_applied'] ?? false);

        // 5. Real Cheshire Oaks route: 43.72 miles -> city_long, base £79.35, mult 1.0000, midpoint £79.35, range £75.38–£83.32
        $destCheshire = new Location('Cheshire Oaks', 'Designer Outlet Cheshire Oaks, Ellesmere Port, Wirral, UK', new Coordinates(53.360, -2.960));
        $routeCheshire = new RouteInformation($origin40, $destCheshire, 70359, 43.72, 3840, 64);
        $inputCheshire = new PricingCalculationInput(
            provider: TaxiProvider::STREETCARS,
            route: $routeCheshire,
            trip: new TripRequest('Manchester Piccadilly Station, Manchester, M60 7RA', 'Designer Outlet Cheshire Oaks, Ellesmere Port, Wirral, UK'),
            config: $this->streetcarsConfig,
        );
        $breakdownCheshire = $this->strategy->calculateBreakdown($inputCheshire);
        $this->assertEquals('city_long', $breakdownCheshire->extraItems['trip_category']);
        $this->assertEquals(1.0000, $breakdownCheshire->calibrationMultiplier);
        $this->assertEquals(79.35, $breakdownCheshire->subtotal);
        $this->assertEquals(79.35, $breakdownCheshire->total);
        $this->assertEquals(75.38, round($breakdownCheshire->total * 0.95, 2));
        $this->assertEquals(83.32, round($breakdownCheshire->total * 1.05, 2));
        $this->assertFalse($breakdownCheshire->extraItems['geographic_calibration_applied'] ?? false);

        // 6. Airport routes > 15 miles must NOT become city_long
        $airportOrigin = new Location('Airport', 'Manchester Airport (MAN)', new Coordinates(53.361, -2.272));
        $routeAirportCity = new RouteInformation($airportOrigin, $origin40, 25750, 16.0, 1800, 30);
        $inputAirportCity = new PricingCalculationInput(
            provider: TaxiProvider::STREETCARS,
            route: $routeAirportCity,
            trip: new TripRequest('Manchester Airport (MAN)', 'Manchester Piccadilly Station'),
            config: $this->streetcarsConfig,
        );
        $breakdownAirportCity = $this->strategy->calculateBreakdown($inputAirportCity);
        $this->assertEquals('airport_to_city', $breakdownAirportCity->extraItems['trip_category']);
        $this->assertEquals(1.0440, $breakdownAirportCity->calibrationMultiplier);

        $routeAirportSuburb = new RouteInformation($airportOrigin, $destCheshire, 56327, 35.0, 3000, 50);
        $inputAirportSuburb = new PricingCalculationInput(
            provider: TaxiProvider::STREETCARS,
            route: $routeAirportSuburb,
            trip: new TripRequest('Manchester Airport (MAN)', 'Designer Outlet Cheshire Oaks, Ellesmere Port, Wirral, UK'),
            config: $this->streetcarsConfig,
        );
        $breakdownAirportSuburb = $this->strategy->calculateBreakdown($inputAirportSuburb);
        $this->assertEquals('airport_to_suburb', $breakdownAirportSuburb->extraItems['trip_category']);
        $this->assertNotEquals('city_long', $breakdownAirportSuburb->extraItems['trip_category']);
    }

    public function test_uber_bolt_veezu_are_unaffected(): void
    {
        config(['taxiscanner.streetcars.geographic_calibration.enabled' => true]);

        $input = $this->createInput(
            pickupQuery: 'Manchester Airport (MAN)',
            dropoffQuery: 'Handforth, Wilmslow, UK',
            distanceMiles: 3.5,
            durationMinutes: 10,
            dropoffCoords: new Coordinates(53.345, -2.215),
        );

        $uberStrategy = new UberPricingStrategy;
        $boltStrategy = new BoltPricingStrategy;
        $veezuStrategy = new VeezuPricingStrategy;

        $uberBreakdown = $uberStrategy->calculateBreakdown($input);
        $boltBreakdown = $boltStrategy->calculateBreakdown($input);
        $veezuBreakdown = $veezuStrategy->calculateBreakdown($input);

        $this->assertFalse($uberBreakdown->extraItems['geographic_calibration_applied'] ?? false);
        $this->assertFalse($boltBreakdown->extraItems['geographic_calibration_applied'] ?? false);
        $this->assertFalse($veezuBreakdown->extraItems['geographic_calibration_applied'] ?? false);
    }

    public function test_minimum_fare_behavior_still_works(): void
    {
        config(['taxiscanner.streetcars.geographic_calibration.enabled' => true]);

        // Artificially high minimum fare to test threshold enforcement
        $this->streetcarsConfig->minimum_fare = 50.00;

        $input = $this->createInput(
            pickupQuery: 'Manchester Airport (MAN)',
            dropoffQuery: 'Water Lane, Wilmslow, UK',
            distanceMiles: 5.0,
            durationMinutes: 10,
            dropoffCoords: new Coordinates(53.326, -2.235),
            dropoffPostcode: 'SK9 1PB',
        );

        $breakdown = $this->strategy->calculateBreakdown($input);
        $range = $this->strategy->calculate($input);

        $this->assertGreaterThanOrEqual(50.00, $breakdown->total);
        $this->assertGreaterThanOrEqual(50.00, $range->min);
        $this->assertGreaterThanOrEqual(50.00, $range->max);
    }

    public function test_price_range_generation_still_works(): void
    {
        config(['taxiscanner.streetcars.geographic_calibration.enabled' => true]);

        $input = $this->createInput(
            pickupQuery: 'Manchester Airport (MAN)',
            dropoffQuery: 'Handforth, Wilmslow, UK',
            distanceMiles: 3.5,
            durationMinutes: 10,
            dropoffCoords: new Coordinates(53.345, -2.215),
        );

        $breakdown = $this->strategy->calculateBreakdown($input);
        $range = $this->strategy->calculate($input);

        $this->assertEquals(22.00, $breakdown->total);
        $this->assertEquals(round(22.00 * 0.95, 2), $range->min);
        $this->assertEquals(round(22.00 * 1.05, 2), $range->max);
    }

    public function test_regression_wilmslow_applies_multiplier_1_1721_and_floor(): void
    {
        config(['taxiscanner.streetcars.geographic_calibration.enabled' => true]);

        $input = $this->createInput(
            pickupQuery: 'Manchester Airport (MAN)',
            dropoffQuery: 'Wilmslow',
            distanceMiles: 6.73,
            durationMinutes: 15,
            dropoffCoords: new Coordinates(53.326271, -2.235254),
            dropoffFormatted: 'Water Lane, Wilmslow, SK9 5AH, United Kingdom',
            dropoffPostcode: 'SK9 5AH',
        );

        $breakdown = $this->strategy->calculateBreakdown($input);
        $range = $this->strategy->calculate($input);

        $this->assertTrue($breakdown->extraItems['geographic_calibration_applied']);
        $this->assertEquals('handforth_wilmslow', $breakdown->extraItems['geographic_zone']);
        $this->assertEquals(1.1721, $breakdown->extraItems['geographic_multiplier']);
        $this->assertEquals(1.1721, $breakdown->calibrationMultiplier);
        $this->assertEquals(18.77, $breakdown->subtotal);
        $this->assertEquals(22.00, $breakdown->total);
        $this->assertEquals(20.90, $range->min);
        $this->assertEquals(23.10, $range->max);
    }

    public function test_regression_stockport_applies_multiplier_1_2580_to_final_total(): void
    {
        config(['taxiscanner.streetcars.geographic_calibration.enabled' => true]);

        $input = $this->createInput(
            pickupQuery: 'Manchester Airport (MAN)',
            dropoffQuery: 'Stockport',
            distanceMiles: 8.38,
            durationMinutes: 17,
            dropoffCoords: new Coordinates(53.41013, -2.158088),
            dropoffFormatted: 'Stockport, Greater Manchester, England, United Kingdom',
            dropoffPostcode: null,
        );

        $breakdown = $this->strategy->calculateBreakdown($input);
        $range = $this->strategy->calculate($input);

        $this->assertTrue($breakdown->extraItems['geographic_calibration_applied']);
        $this->assertEquals('stockport', $breakdown->extraItems['geographic_zone']);
        $this->assertEquals(1.2580, $breakdown->extraItems['geographic_multiplier']);
        $this->assertEquals(1.2580, $breakdown->calibrationMultiplier);
        $this->assertEquals(21.61, $breakdown->subtotal);
        // Specifically proves 1.2580 is applied to the final total: round(21.61 * 1.2580, 2) = 27.19
        $this->assertEquals(27.19, $breakdown->total);
        $this->assertEquals(round(21.61 * 1.2580, 2), $breakdown->total);
        $this->assertEquals(25.83, $range->min);
        $this->assertEquals(28.55, $range->max);
    }

    public function test_regression_didsbury_applies_geographic_calibration(): void
    {
        config(['taxiscanner.streetcars.geographic_calibration.enabled' => true]);

        $input = $this->createInput(
            pickupQuery: 'Manchester Airport (MAN)',
            dropoffQuery: 'Didsbury, Manchester, UK',
            distanceMiles: 6.81,
            durationMinutes: 17,
            dropoffCoords: new Coordinates(53.41761, -2.231555),
            dropoffFormatted: 'Didsbury, Manchester, Greater Manchester, England, United Kingdom',
            dropoffPostcode: 'M20',
        );

        $breakdown = $this->strategy->calculateBreakdown($input);
        $range = $this->strategy->calculate($input);

        $this->assertTrue($breakdown->extraItems['geographic_calibration_applied'] ?? false);
        $this->assertEquals('didsbury', $breakdown->extraItems['geographic_zone']);
        $this->assertEquals(1.1518, $breakdown->extraItems['geographic_multiplier']);
        $this->assertEquals(1.1518, $breakdown->calibrationMultiplier);
        $this->assertEquals(19.10, $breakdown->subtotal);
        $this->assertEquals(22.00, $breakdown->total);
        $this->assertEquals(20.90, $range->min);
        $this->assertEquals(23.10, $range->max);
    }

    public function test_inter_suburb_classification_and_pricing_for_stockport_to_trafford_park(): void
    {
        config(['taxiscanner.streetcars.geographic_calibration.enabled' => true]);

        $origin = new Location(
            query: 'Stockport Railway Station, Station Road, Stockport, UK',
            formattedAddress: 'Stockport Railway Station, Grand Central Way, Stockport, SK3 9HZ, United Kingdom',
            coordinates: new Coordinates(53.4074, -2.1634),
            city: 'Stockport',
            postcode: 'SK3 9HZ',
        );

        $dest = new Location(
            query: 'Nash Road, Trafford Park, Stretford, Manchester, UK',
            formattedAddress: 'Nash Road, Trafford Park, Manchester, M17 1SX, United Kingdom',
            coordinates: new Coordinates(53.476662, -2.336831),
            city: 'Manchester',
            postcode: 'M17 1SX',
        );

        $route = new RouteInformation($origin, $dest, 19556, 12.15, 1211, 20);
        $trip = new TripRequest($origin->query, $dest->query);

        $input = new PricingCalculationInput(
            provider: TaxiProvider::STREETCARS,
            route: $route,
            trip: $trip,
            config: $this->streetcarsConfig,
        );

        $breakdown = $this->strategy->calculateBreakdown($input);
        $range = $this->strategy->calculate($input);

        // 1. Confirms classification as inter_suburb
        $this->assertEquals('inter_suburb', $breakdown->extraItems['trip_category']);
        $this->assertEquals('inter_suburb', $breakdown->extraItems['calibration_category']);

        // 2. Confirms multiplier is 1.0000 (not city_medium 0.6971)
        $this->assertEquals(1.0000, $breakdown->calibrationMultiplier);
        $this->assertFalse($breakdown->extraItems['geographic_calibration_applied'] ?? false);

        // 3. Confirms base subtotal £24.44 and calibrated total £24.44
        $this->assertEquals(24.44, $breakdown->subtotal);
        $this->assertEquals(24.44, $breakdown->total);

        // 4. Confirms TaxiScanner ±5% range is £23.22–£25.66
        $this->assertEquals(23.22, $range->min);
        $this->assertEquals(25.66, $range->max);
    }

    public function test_non_airport_and_airport_trip_category_isolation_regressions(): void
    {
        config(['taxiscanner.streetcars.geographic_calibration.enabled' => true]);

        // A. Existing Piccadilly -> Arndale remains city_short (multiplier 0.7040)
        $piccadilly = new Location('Piccadilly', 'Manchester Piccadilly Station, Manchester, M60 7RA, UK', new Coordinates(53.4774, -2.2312), city: 'Manchester', postcode: 'M60 7RA');
        $arndale = new Location('Arndale', 'Manchester Arndale Centre, Manchester, M4 3AQ, UK', new Coordinates(53.4839, -2.2426), city: 'Manchester', postcode: 'M4 3AQ');
        $routeArndale = new RouteInformation($piccadilly, $arndale, 1300, 0.81, 360, 6);
        $inputArndale = new PricingCalculationInput(TaxiProvider::STREETCARS, $routeArndale, new TripRequest($piccadilly->query, $arndale->query), $this->streetcarsConfig);
        $breakdownArndale = $this->strategy->calculateBreakdown($inputArndale);
        $this->assertEquals('city_short', $breakdownArndale->extraItems['trip_category']);
        $this->assertEquals(0.7040, $breakdownArndale->calibrationMultiplier);

        // B. Piccadilly -> Salford Quays (4.01 mi) remains city_medium (multiplier 0.6971)
        $salfordQuays = new Location('Salford Quays', 'Salford Quays, Salford, M50 3AZ, UK', new Coordinates(53.4722, -2.2858), city: 'Salford', postcode: 'M50 3AZ');
        $routeSalford = new RouteInformation($piccadilly, $salfordQuays, 6453, 4.01, 720, 12);
        $inputSalford = new PricingCalculationInput(TaxiProvider::STREETCARS, $routeSalford, new TripRequest($piccadilly->query, $salfordQuays->query), $this->streetcarsConfig);
        $breakdownSalford = $this->strategy->calculateBreakdown($inputSalford);
        $this->assertEquals('city_medium', $breakdownSalford->extraItems['trip_category']);
        $this->assertEquals(0.6971, $breakdownSalford->calibrationMultiplier);

        // C. Piccadilly -> Trafford Centre (9.38 mi) remains city_medium (multiplier 0.6971)
        $traffordCentre = new Location('Trafford Centre', 'The Trafford Centre, Manchester, M17 8AA, UK', new Coordinates(53.4657, -2.3498), city: 'Trafford', postcode: 'M17 8AA');
        $routeTrafford = new RouteInformation($piccadilly, $traffordCentre, 15095, 9.38, 1260, 21);
        $inputTrafford = new PricingCalculationInput(TaxiProvider::STREETCARS, $routeTrafford, new TripRequest($piccadilly->query, $traffordCentre->query), $this->streetcarsConfig);
        $breakdownTrafford = $this->strategy->calculateBreakdown($inputTrafford);
        $this->assertEquals('city_medium', $breakdownTrafford->extraItems['trip_category']);
        $this->assertEquals(0.6971, $breakdownTrafford->calibrationMultiplier);

        // D. Cheshire Oaks (43.72 mi) remains city_long (multiplier 1.0000)
        $cheshireOaks = new Location('Cheshire Oaks', 'Designer Outlet Cheshire Oaks, Ellesmere Port, Wirral, CH65 9JJ, UK', new Coordinates(53.360, -2.960), city: 'Ellesmere Port', postcode: 'CH65 9JJ');
        $routeCheshire = new RouteInformation($piccadilly, $cheshireOaks, 70359, 43.72, 3840, 64);
        $inputCheshire = new PricingCalculationInput(TaxiProvider::STREETCARS, $routeCheshire, new TripRequest($piccadilly->query, $cheshireOaks->query), $this->streetcarsConfig);
        $breakdownCheshire = $this->strategy->calculateBreakdown($inputCheshire);
        $this->assertEquals('city_long', $breakdownCheshire->extraItems['trip_category']);
        $this->assertEquals(1.0000, $breakdownCheshire->calibrationMultiplier);

        // E. Airport -> Stockport remains airport_to_suburb (multiplier 1.2580) and does NOT become inter_suburb
        $airport = new Location('Manchester Airport', 'Manchester Airport (MAN), Ringway, Manchester, M90 1QX, UK', new Coordinates(53.3588, -2.2727), city: 'Manchester', postcode: 'M90 1QX');
        $stockportTown = new Location('Stockport', 'Stockport Town Centre, Stockport, SK1 1ES, UK', new Coordinates(53.411, -2.160), city: 'Stockport', postcode: 'SK1 1ES');
        $routeAirportStockport = new RouteInformation($airport, $stockportTown, 10493, 6.52, 1020, 17);
        $inputAirportStockport = new PricingCalculationInput(TaxiProvider::STREETCARS, $routeAirportStockport, new TripRequest($airport->query, $stockportTown->query), $this->streetcarsConfig);
        $breakdownAirportStockport = $this->strategy->calculateBreakdown($inputAirportStockport);
        $this->assertEquals('airport_to_suburb', $breakdownAirportStockport->extraItems['trip_category']);
        $this->assertEquals(1.2580, $breakdownAirportStockport->calibrationMultiplier);

        // F. Airport -> Manchester city centre remains airport_to_city (multiplier 1.0440)
        $portlandStreet = new Location('Portland Street', 'Portland Street, Manchester, M1 4GS, UK', new Coordinates(53.4785, -2.2385), city: 'Manchester', postcode: 'M1 4GS');
        $routeAirportCity = new RouteInformation($airport, $portlandStreet, 15192, 9.44, 1620, 27);
        $inputAirportCity = new PricingCalculationInput(TaxiProvider::STREETCARS, $routeAirportCity, new TripRequest($airport->query, $portlandStreet->query), $this->streetcarsConfig);
        $breakdownAirportCity = $this->strategy->calculateBreakdown($inputAirportCity);
        $this->assertEquals('airport_to_city', $breakdownAirportCity->extraItems['trip_category']);
        $this->assertEquals(1.0440, $breakdownAirportCity->calibrationMultiplier);
    }
}
