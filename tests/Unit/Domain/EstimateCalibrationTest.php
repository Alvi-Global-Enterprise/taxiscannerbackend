<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Location\DTOs\Coordinates;
use App\Domain\Location\DTOs\Location;
use App\Domain\Location\DTOs\RouteInformation;
use App\Domain\Pricing\DTOs\PricingCalculationInput;
use App\Domain\Pricing\Services\EstimateCalibrationService;
use App\Domain\Pricing\Strategies\UberPricingStrategy;
use App\Domain\Taxi\Contracts\EstimateEngineInterface;
use App\Domain\Taxi\DTOs\TripRequest;
use App\Domain\Taxi\Enums\TaxiProvider;
use App\Domain\Taxi\Models\CalibrationObservation;
use App\Domain\Taxi\Models\Provider;
use App\Domain\Taxi\Models\ProviderPricingConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Psr\Log\NullLogger;
use RuntimeException;
use Tests\TestCase;

class EstimateCalibrationTest extends TestCase
{
    use RefreshDatabase;

    private EstimateCalibrationService $calibrationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        CalibrationObservation::query()->delete();

        $this->calibrationService = new EstimateCalibrationService(
            logger: new NullLogger,
        );
    }

    // 1. Record observation with correct difference and multiplier
    public function test_1_record_observation_calculates_difference_and_multiplier(): void
    {
        $observation = $this->calibrationService->recordObservation([
            'provider' => 'uber',
            'pickup' => 'Manchester Airport',
            'dropoff' => 'Manchester City Centre',
            'route_distance' => 10.0,
            'route_duration' => 25,
            'observed_real_world_fare' => 30.00,
            'estimated_fare' => 25.00,
            'trip_category' => 'airport',
            'notes' => 'Friday night sample',
        ]);

        $this->assertInstanceOf(CalibrationObservation::class, $observation);
        $this->assertEquals('uber', $observation->provider_slug);
        $this->assertEquals(30.00, $observation->observed_real_world_fare);
        $this->assertEquals(25.00, $observation->estimated_fare);
        $this->assertEquals(20.00, $observation->difference_percentage); // (30-25)/25 = +20%
        $this->assertEquals(1.2000, $observation->recommended_multiplier); // 30/25 = 1.2000
        $this->assertFalse($observation->is_outlier);
    }

    // 2. Reject zero or negative fares
    public function test_2_rejects_invalid_fares(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calibrationService->recordObservation([
            'provider' => 'uber',
            'pickup' => 'A',
            'dropoff' => 'B',
            'route_distance' => 5.0,
            'route_duration' => 10,
            'observed_real_world_fare' => 0.0,
            'estimated_fare' => 20.0,
        ]);
    }

    // 3. Flags extreme ratios as outliers
    public function test_3_flags_extreme_ratios_as_outliers(): void
    {
        $obs = $this->calibrationService->recordObservation([
            'provider' => 'bolt',
            'pickup' => 'A',
            'dropoff' => 'B',
            'route_distance' => 5.0,
            'route_duration' => 10,
            'observed_real_world_fare' => 70.00, // 70 / 20 = 3.5x
            'estimated_fare' => 20.00,
        ]);

        $this->assertTrue($obs->is_outlier);
    }

    // 4. Insufficient samples guard
    public function test_4_insufficient_samples_guard(): void
    {
        // 0 samples recorded
        $rec = $this->calibrationService->calculateRecommendedMultiplier('uber', minSamples: 3);

        $this->assertFalse($rec->hasEnoughSamples);
        $this->assertEquals(0, $rec->sampleCount);
        $this->assertEquals(1.0000, $rec->recommendedMultiplier);
        $this->assertStringContainsString('Insufficient observation samples', (string) $rec->notes);
    }

    // 5. Multiple observations calculate mean and median
    public function test_5_multiple_observations_calculate_mean_and_median(): void
    {
        // Sample 1: ratio = 30 / 25 = 1.2000
        $this->calibrationService->recordObservation([
            'provider' => 'streetcars',
            'pickup' => 'Manchester Airport',
            'dropoff' => 'Piccadilly',
            'route_distance' => 10.0,
            'route_duration' => 25,
            'observed_real_world_fare' => 30.00,
            'estimated_fare' => 25.00,
        ]);

        // Sample 2: ratio = 22 / 20 = 1.1000
        $this->calibrationService->recordObservation([
            'provider' => 'streetcars',
            'pickup' => 'Old Trafford',
            'dropoff' => 'City Centre',
            'route_distance' => 4.0,
            'route_duration' => 15,
            'observed_real_world_fare' => 22.00,
            'estimated_fare' => 20.00,
        ]);

        // Sample 3: ratio = 18 / 15 = 1.2000
        $this->calibrationService->recordObservation([
            'provider' => 'streetcars',
            'pickup' => 'Salford Quays',
            'dropoff' => 'Deansgate',
            'route_distance' => 3.0,
            'route_duration' => 12,
            'observed_real_world_fare' => 18.00,
            'estimated_fare' => 15.00,
        ]);

        $rec = $this->calibrationService->calculateRecommendedMultiplier('streetcars', minSamples: 2);

        $this->assertTrue($rec->hasEnoughSamples);
        $this->assertEquals(3, $rec->sampleCount);
        // Ratios: 1.10, 1.20, 1.20 => Median is 1.20
        $this->assertEquals(1.2000, $rec->medianMultiplier);
        $this->assertEquals(1.2000, $rec->recommendedMultiplier);
        // Mean is (1.10 + 1.20 + 1.20) / 3 = 1.1667
        $this->assertEquals(1.1667, $rec->meanMultiplier);
    }

    // 6. Outlier resistance preserves robust median
    public function test_6_outlier_resistance_preserves_robust_median(): void
    {
        // 5 samples around 1.15x
        $normalFares = [
            ['obs' => 23.0, 'est' => 20.0], // 1.15
            ['obs' => 24.0, 'est' => 20.0], // 1.20
            ['obs' => 22.5, 'est' => 20.0], // 1.125
            ['obs' => 23.5, 'est' => 20.0], // 1.175
            ['obs' => 22.0, 'est' => 20.0], // 1.10
        ];

        foreach ($normalFares as $f) {
            $this->calibrationService->recordObservation([
                'provider' => 'veezu',
                'pickup' => 'Location A',
                'dropoff' => 'Location B',
                'route_distance' => 5.0,
                'route_duration' => 15,
                'observed_real_world_fare' => $f['obs'],
                'estimated_fare' => $f['est'],
            ]);
        }

        $rec = $this->calibrationService->calculateRecommendedMultiplier('veezu', minSamples: 3);

        $this->assertTrue($rec->hasEnoughSamples);
        $this->assertEquals(5, $rec->sampleCount);
        // Median of [1.10, 1.125, 1.15, 1.175, 1.20] is 1.1500
        $this->assertEquals(1.1500, $rec->medianMultiplier);
        $this->assertEquals(1.1500, $rec->recommendedMultiplier);
    }

    // 7. Provider isolation: observations for one provider do not affect others
    public function test_7_provider_isolation(): void
    {
        // Add 3 samples for Uber
        for ($i = 0; $i < 3; $i++) {
            $this->calibrationService->recordObservation([
                'provider' => 'uber',
                'pickup' => 'Manchester Airport',
                'dropoff' => 'Manchester City Centre',
                'route_distance' => 10.0,
                'route_duration' => 25,
                'observed_real_world_fare' => 30.00,
                'estimated_fare' => 20.00, // ratio 1.50
            ]);
        }

        $uberRec = $this->calibrationService->calculateRecommendedMultiplier('uber', minSamples: 3);
        $boltRec = $this->calibrationService->calculateRecommendedMultiplier('bolt', minSamples: 3);

        $this->assertTrue($uberRec->hasEnoughSamples);
        $this->assertEquals(1.5000, $uberRec->recommendedMultiplier);

        $this->assertFalse($boltRec->hasEnoughSamples);
        $this->assertEquals(0, $boltRec->sampleCount);
        $this->assertEquals(1.0000, $boltRec->recommendedMultiplier);
    }

    // 8. Apply calibration requires minimum samples
    public function test_8_apply_calibration_fails_without_enough_samples(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('minimum of 3 observations required, but only 0 recorded');

        $this->calibrationService->applyCalibration('bolt', minSamples: 3);
    }

    // 9. Successfully apply recommended calibration to provider config
    public function test_9_successfully_apply_recommended_calibration(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->calibrationService->recordObservation([
                'provider' => 'uber',
                'pickup' => 'Manchester Airport',
                'dropoff' => 'Manchester City Centre',
                'route_distance' => 10.0,
                'route_duration' => 25,
                'observed_real_world_fare' => 30.00,
                'estimated_fare' => 25.00, // ratio 1.2000
            ]);
        }

        $config = $this->calibrationService->applyCalibration('uber', minSamples: 3);

        $this->assertEquals(1.2000, $config->calibration_multiplier);

        // Verify persisted in database
        $fresh = ProviderPricingConfig::where('provider_id', Provider::where('slug', 'uber')->first()->id)->first();
        $this->assertEquals(1.2000, $fresh->calibration_multiplier);
        $this->assertArrayHasKey('last_calibrated_at', $fresh->config_data);
    }

    // 10. Calibration multiplier properly scales fare in pricing strategy
    public function test_10_calibration_multiplier_scales_fare_in_pricing_strategy(): void
    {
        $origin = new Location('A', 'A, UK', new Coordinates(53.4, -2.2));
        $dest = new Location('B', 'B, UK', new Coordinates(53.5, -2.3));
        $route = RouteInformation::fromCalculatedValues($origin, $dest, 16093, 1200); // 10 miles, 20 mins
        $trip = new TripRequest('A', 'B');

        $strategy = new UberPricingStrategy;

        // Base config without calibration: calibration_multiplier = 1.0
        $provider = Provider::where('slug', 'uber')->first();
        $config = ProviderPricingConfig::where('provider_id', $provider->id)->first();
        $config->update(['calibration_multiplier' => 1.0000]);

        $input1 = new PricingCalculationInput(
            provider: TaxiProvider::UBER,
            trip: $trip,
            route: $route,
            config: $config->fresh(),
        );

        $quote1 = $strategy->calculate($input1);

        // Now calibrate Uber with 1.2000 (+20%)
        $config->update(['calibration_multiplier' => 1.2000]);

        $input2 = new PricingCalculationInput(
            provider: TaxiProvider::UBER,
            trip: $trip,
            route: $route,
            config: $config->fresh(),
        );

        $quote2 = $strategy->calculate($input2);

        // Quote 2 should be scaled by approximately 1.20x
        $expectedMin = round($quote1->min * 1.20, 2);
        $expectedMax = round($quote1->max * 1.20, 2);

        $this->assertEqualsWithDelta($expectedMin, $quote2->min, 0.20);
        $this->assertEqualsWithDelta($expectedMax, $quote2->max, 0.20);
        $this->assertGreaterThan($quote1->min, $quote2->min);
    }

    // 11. End-to-end EstimateEngine reflects calibrated pricing
    public function test_11_estimate_engine_reflects_calibrated_pricing(): void
    {
        $engine = app(EstimateEngineInterface::class);

        $origin = new Location('Manchester Airport', 'Manchester Airport (MAN), UK', new Coordinates(53.3588, -2.2727));
        $dest = new Location('Manchester Piccadilly', 'Manchester Piccadilly, UK', new Coordinates(53.4774, -2.2312));
        $route = RouteInformation::fromCalculatedValues($origin, $dest, 16093, 1200);
        $trip = new TripRequest('Manchester Airport', 'Manchester Piccadilly');

        $provider = Provider::where('slug', 'bolt')->first();
        $config = ProviderPricingConfig::where('provider_id', $provider->id)->first();
        $config->update(['calibration_multiplier' => 1.0000]);

        $uncalibrated = $engine->calculateEstimate(TaxiProvider::BOLT, $trip, $route);

        // Apply +15% calibration
        $config->update(['calibration_multiplier' => 1.1500]);

        $calibrated = $engine->calculateEstimate(TaxiProvider::BOLT, $trip, $route);

        $this->assertGreaterThan($uncalibrated->priceRange->min, $calibrated->priceRange->min);
        $this->assertEquals(
            1.1500,
            $calibrated->metadata['breakdown']['calibration_multiplier'] ?? 1.0
        );
    }

    // 12. Artisan calibrate command execution
    public function test_12_artisan_calibrate_command(): void
    {
        $this->artisan('taxiscanner:calibrate')
            ->assertSuccessful()
            ->expectsOutputToContain('UBER')
            ->expectsOutputToContain('BOLT')
            ->expectsOutputToContain('STREETCARS')
            ->expectsOutputToContain('VEEZU');
    }

    // 13. Category is assigned correctly
    public function test_13_category_is_assigned_correctly(): void
    {
        // 1. Short city trip (<= 4 miles)
        $cat1 = $this->calibrationService->classifyTripCategory(
            'Manchester Piccadilly Station',
            'Manchester Arndale Centre',
            0.81
        );
        $this->assertEquals('city_short', $cat1);

        $cat2 = $this->calibrationService->classifyTripCategory(
            'Manchester Piccadilly Station',
            'Old Trafford',
            3.48
        );
        $this->assertEquals('city_short', $cat2);

        // 2. Airport to city center
        $cat3 = $this->calibrationService->classifyTripCategory(
            'Manchester Airport (MAN)',
            'Manchester Piccadilly Station',
            9.44
        );
        $this->assertEquals('airport_to_city', $cat3);

        $cat4 = $this->calibrationService->classifyTripCategory(
            'Manchester Airport (MAN)',
            'Manchester City Centre (Portland Street)',
            9.44
        );
        $this->assertEquals('airport_to_city', $cat4);

        // 3. City to airport
        $cat5 = $this->calibrationService->classifyTripCategory(
            'Manchester Piccadilly Station',
            'Manchester Airport (MAN)',
            9.44
        );
        $this->assertEquals('city_to_airport', $cat5);

        // 4. Airport to suburb / non-city-center
        $cat6 = $this->calibrationService->classifyTripCategory(
            'Manchester Airport (MAN)',
            'Vision Express Opticians at Tesco - Handforth, Kiln Croft Lane, Wilmslow',
            4.81
        );
        $this->assertEquals('airport_to_suburb', $cat6);

        // 5. Medium city trip (> 4 miles AND <= 15 miles, no airport)
        $cat7 = $this->calibrationService->classifyTripCategory(
            'Didsbury, Manchester',
            'Bury Town Centre',
            12.50
        );
        $this->assertEquals('city_medium', $cat7);

        // 6. Non-airport distance boundaries
        // 4.0 miles -> city_short
        $boundary40 = $this->calibrationService->classifyTripCategory(
            'Manchester Piccadilly Station',
            'Salford Precinct',
            4.00
        );
        $this->assertEquals('city_short', $boundary40);

        // 4.01 miles -> city_medium
        $boundary401 = $this->calibrationService->classifyTripCategory(
            'Manchester Piccadilly Station',
            'Salford Quays',
            4.01
        );
        $this->assertEquals('city_medium', $boundary401);

        // 15.0 miles -> city_medium
        $boundary150 = $this->calibrationService->classifyTripCategory(
            'Manchester Piccadilly Station',
            'Rochdale Town Centre',
            15.00
        );
        $this->assertEquals('city_medium', $boundary150);

        // 15.01 miles -> city_long
        $boundary1501 = $this->calibrationService->classifyTripCategory(
            'Manchester Piccadilly Station',
            'Littleborough',
            15.01
        );
        $this->assertEquals('city_long', $boundary1501);

        // 43.72 miles -> city_long (Cheshire Oaks)
        $cheshireOaks = $this->calibrationService->classifyTripCategory(
            'Manchester Piccadilly Station, Manchester, M60 7RA',
            'Designer Outlet Cheshire Oaks, Ellesmere Port, Wirral, UK',
            43.72
        );
        $this->assertEquals('city_long', $cheshireOaks);

        // 7. Airport routes must NOT accidentally become city_long, even when > 15 miles
        $airportLongCity = $this->calibrationService->classifyTripCategory(
            'Manchester Airport (MAN)',
            'Manchester Piccadilly Station',
            16.00
        );
        $this->assertEquals('airport_to_city', $airportLongCity);

        $airportLongSuburb = $this->calibrationService->classifyTripCategory(
            'Manchester Airport (MAN)',
            'Designer Outlet Cheshire Oaks, Ellesmere Port, Wirral, UK',
            35.00
        );
        $this->assertEquals('airport_to_suburb', $airportLongSuburb);

        $cityLongToAirport = $this->calibrationService->classifyTripCategory(
            'Manchester Piccadilly Station',
            'Manchester Airport (MAN)',
            16.00
        );
        $this->assertEquals('city_to_airport', $cityLongToAirport);
    }

    // 14. Category calibration remains inactive below minimum sample count (default: 3)
    public function test_14_category_calibration_remains_inactive_below_minimum_sample_count(): void
    {
        // Add only 2 observations for city_short (Piccadilly to Arndale and Old Trafford)
        $this->calibrationService->recordObservation([
            'provider' => 'streetcars',
            'pickup' => 'Manchester Piccadilly Station',
            'dropoff' => 'Manchester Arndale Centre',
            'route_distance' => 0.81,
            'route_duration' => 6,
            'observed_real_world_fare' => 3.75,
            'estimated_fare' => 5.44, // 0.6893
            'trip_category' => 'city_short',
        ]);

        $this->calibrationService->recordObservation([
            'provider' => 'streetcars',
            'pickup' => 'Manchester Piccadilly Station',
            'dropoff' => 'Old Trafford',
            'route_distance' => 3.48,
            'route_duration' => 14,
            'observed_real_world_fare' => 6.00,
            'estimated_fare' => 8.87, // 0.6764
            'trip_category' => 'city_short',
        ]);

        // Default minimum is 3 samples
        $rec = $this->calibrationService->calculateRecommendedMultiplier('streetcars', category: 'city_short', minSamples: 3);

        $this->assertFalse($rec->hasEnoughSamples);
        $this->assertEquals(2, $rec->sampleCount);
        $this->assertEquals(1.0000, $rec->recommendedMultiplier); // Inactive: leaves production pricing unchanged
        $this->assertEquals(0.6829, $rec->meanMultiplier);
        $this->assertEquals(0.6829, $rec->medianMultiplier);
        $this->assertStringContainsString('INSUFFICIENT SAMPLES', (string) $rec->notes);

        // Attempting to apply calibration must fail safely
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('minimum of 3 observations required, but only 2 recorded');

        $this->calibrationService->applyCalibration('streetcars', minSamples: 3);
    }

    // 15. Global multiplier remains unchanged in database
    public function test_15_global_multiplier_remains_unchanged(): void
    {
        $provider = Provider::where('slug', 'streetcars')->first();
        $config = ProviderPricingConfig::where('provider_id', $provider->id)->where('is_active', true)->first();

        // Must remain exactly 1.0000
        $this->assertEquals(1.0000, $config->calibration_multiplier);
    }

    // 16. Existing calibration observations remain compatible
    public function test_16_existing_calibration_observations_remain_compatible(): void
    {
        // Sample without category
        $this->calibrationService->recordObservation([
            'provider' => 'streetcars',
            'pickup' => 'Generic Location A',
            'dropoff' => 'Generic Location B',
            'route_distance' => 5.0,
            'route_duration' => 15,
            'observed_real_world_fare' => 20.00,
            'estimated_fare' => 20.00,
            'trip_category' => null,
        ]);

        // Sample with category
        $this->calibrationService->recordObservation([
            'provider' => 'streetcars',
            'pickup' => 'Manchester Airport',
            'dropoff' => 'Manchester Piccadilly Station',
            'route_distance' => 9.44,
            'route_duration' => 27,
            'observed_real_world_fare' => 26.00,
            'estimated_fare' => 24.30,
            'trip_category' => 'airport_to_city',
        ]);

        $globalRec = $this->calibrationService->calculateRecommendedMultiplier('streetcars', category: null, minSamples: 1);
        $airportRec = $this->calibrationService->calculateRecommendedMultiplier('streetcars', category: 'airport_to_city', minSamples: 1);

        $this->assertEquals(2, $globalRec->sampleCount);
        $this->assertEquals(1, $airportRec->sampleCount);
        $this->assertEquals(1.0700, $airportRec->recommendedMultiplier);
    }

    // 17. Airport and city categories can have separate recommended multipliers
    public function test_17_airport_and_city_categories_can_have_separate_recommended_multipliers(): void
    {
        // 3 samples for city_short (around ~0.69x)
        foreach ([0.68, 0.69, 0.70] as $ratio) {
            $this->calibrationService->recordObservation([
                'provider' => 'streetcars',
                'pickup' => 'Piccadilly',
                'dropoff' => 'Arndale',
                'route_distance' => 1.0,
                'route_duration' => 5,
                'observed_real_world_fare' => 10.0 * $ratio,
                'estimated_fare' => 10.0,
                'trip_category' => 'city_short',
            ]);
        }

        // 3 samples for airport_to_city (around ~1.07x)
        foreach ([1.05, 1.07, 1.09] as $ratio) {
            $this->calibrationService->recordObservation([
                'provider' => 'streetcars',
                'pickup' => 'Manchester Airport',
                'dropoff' => 'Manchester City Centre',
                'route_distance' => 10.0,
                'route_duration' => 25,
                'observed_real_world_fare' => 25.0 * $ratio,
                'estimated_fare' => 25.0,
                'trip_category' => 'airport_to_city',
            ]);
        }

        $cityRec = $this->calibrationService->calculateRecommendedMultiplier('streetcars', category: 'city_short', minSamples: 3);
        $airportRec = $this->calibrationService->calculateRecommendedMultiplier('streetcars', category: 'airport_to_city', minSamples: 3);

        $this->assertTrue($cityRec->hasEnoughSamples);
        $this->assertTrue($airportRec->hasEnoughSamples);

        $this->assertEquals(0.6900, $cityRec->recommendedMultiplier);
        $this->assertEquals(1.0700, $airportRec->recommendedMultiplier);

        // Verify distinct recommendations via calculateCategoryRecommendations
        $allCatRecs = $this->calibrationService->calculateCategoryRecommendations('streetcars', minSamples: 3);
        $this->assertArrayHasKey('city_short', $allCatRecs);
        $this->assertArrayHasKey('airport_to_city', $allCatRecs);
        $this->assertEquals(0.6900, $allCatRecs['city_short']->recommendedMultiplier);
        $this->assertEquals(1.0700, $allCatRecs['airport_to_city']->recommendedMultiplier);
    }
}
