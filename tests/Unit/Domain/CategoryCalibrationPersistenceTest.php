<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Location\DTOs\Coordinates;
use App\Domain\Location\DTOs\Location;
use App\Domain\Location\DTOs\RouteInformation;
use App\Domain\Pricing\DTOs\PricingCalculationInput;
use App\Domain\Pricing\Services\EstimateCalibrationService;
use App\Domain\Pricing\Strategies\StreetCarsPricingStrategy;
use App\Domain\Taxi\DTOs\TripRequest;
use App\Domain\Taxi\Enums\TaxiProvider;
use App\Domain\Taxi\Models\CalibrationObservation;
use App\Domain\Taxi\Models\Provider;
use App\Domain\Taxi\Models\ProviderPricingConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Log\NullLogger;
use Tests\TestCase;

class CategoryCalibrationPersistenceTest extends TestCase
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

    // A. Category multiplier is selected when present.
    public function test_a_category_multiplier_is_selected_when_present(): void
    {
        $provider = Provider::where('slug', 'streetcars')->first();
        $config = ProviderPricingConfig::where('provider_id', $provider->id)->where('is_active', true)->first();
        $config->update([
            'calibration_multiplier' => 1.0000,
            'config_data' => [
                'category_calibration_multipliers' => [
                    'city_short' => 0.6893,
                ],
            ],
        ]);

        $origin = new Location('Manchester Piccadilly', 'Manchester Piccadilly, UK', new Coordinates(53.4774, -2.2312));
        $dest = new Location('Manchester Arndale', 'Manchester Arndale, UK', new Coordinates(53.4839, -2.2426));
        $route = RouteInformation::fromCalculatedValues($origin, $dest, 1300, 360); // 0.81 miles
        $trip = new TripRequest('Manchester Piccadilly Station', 'Manchester Arndale Centre');

        $input = new PricingCalculationInput(
            provider: TaxiProvider::STREETCARS,
            trip: $trip,
            route: $route,
            config: $config->fresh(),
        );

        $strategy = new StreetCarsPricingStrategy;
        $breakdown = $strategy->calculateBreakdown($input);

        $this->assertEquals(0.6893, $breakdown->calibrationMultiplier);
        $this->assertEquals('city_short', $breakdown->extraItems['calibration_category']);
        $this->assertTrue($breakdown->extraItems['calibration_applied']);
    }

    // B. Global multiplier is used when category multiplier is absent.
    public function test_b_global_multiplier_is_used_when_category_multiplier_is_absent(): void
    {
        $provider = Provider::where('slug', 'streetcars')->first();
        $config = ProviderPricingConfig::where('provider_id', $provider->id)->where('is_active', true)->first();
        $config->update([
            'calibration_multiplier' => 1.1500,
            'config_data' => [
                'category_calibration_multipliers' => [
                    'city_short' => 0.6893,
                ],
            ],
        ]);

        // Route: Manchester Airport to Piccadilly (category: airport_to_city, absent from category_calibration_multipliers)
        $origin = new Location('Manchester Airport', 'Manchester Airport (MAN), UK', new Coordinates(53.3588, -2.2727));
        $dest = new Location('Manchester Piccadilly', 'Manchester Piccadilly, UK', new Coordinates(53.4774, -2.2312));
        $route = RouteInformation::fromCalculatedValues($origin, $dest, 15190, 1620); // 9.44 miles
        $trip = new TripRequest('Manchester Airport (MAN)', 'Manchester Piccadilly Station');

        $input = new PricingCalculationInput(
            provider: TaxiProvider::STREETCARS,
            trip: $trip,
            route: $route,
            config: $config->fresh(),
        );

        $strategy = new StreetCarsPricingStrategy;
        $breakdown = $strategy->calculateBreakdown($input);

        // Must fall back to global scalar calibration_multiplier
        $this->assertEquals(1.1500, $breakdown->calibrationMultiplier);
        $this->assertNull($breakdown->extraItems['calibration_category']);
    }

    // C. Applying city_short does not modify city_medium.
    public function test_c_applying_city_short_does_not_modify_city_medium(): void
    {
        $provider = Provider::where('slug', 'streetcars')->first();
        $config = ProviderPricingConfig::where('provider_id', $provider->id)->where('is_active', true)->first();
        $config->update([
            'calibration_multiplier' => 1.0000,
            'config_data' => [
                'category_calibration_multipliers' => [
                    'city_medium' => 0.7000,
                ],
            ],
        ]);

        // Record 3 samples for city_short (ratio ~0.6893)
        foreach ([0.68, 0.6893, 0.70] as $r) {
            $this->calibrationService->recordObservation([
                'provider' => 'streetcars',
                'pickup' => 'Piccadilly',
                'dropoff' => 'Arndale',
                'route_distance' => 0.8,
                'route_duration' => 5,
                'observed_real_world_fare' => 10.0 * $r,
                'estimated_fare' => 10.0,
                'trip_category' => 'city_short',
            ]);
        }

        // Apply only city_short
        $updatedConfig = $this->calibrationService->applyCalibration('streetcars', minSamples: 3, category: 'city_short');

        $categoryMultipliers = $updatedConfig->config_data['category_calibration_multipliers'];
        $this->assertEquals(0.6893, $categoryMultipliers['city_short']);
        // city_medium must remain 0.7000 untouched
        $this->assertEquals(0.7000, $categoryMultipliers['city_medium']);
        // Global scalar calibration_multiplier must remain untouched at 1.0000
        $this->assertEquals(1.0000, $updatedConfig->calibration_multiplier);
    }

    // D. Applying all READY categories stores each category independently.
    public function test_d_applying_all_ready_categories_stores_each_category_independently(): void
    {
        // 3 samples for city_short (median: 0.6893)
        foreach ([0.68, 0.6893, 0.70] as $r) {
            $this->calibrationService->recordObservation([
                'provider' => 'streetcars',
                'pickup' => 'Piccadilly',
                'dropoff' => 'Arndale',
                'route_distance' => 0.8,
                'route_duration' => 5,
                'observed_real_world_fare' => 10.0 * $r,
                'estimated_fare' => 10.0,
                'trip_category' => 'city_short',
            ]);
        }

        // 3 samples for airport_to_city (median: 1.0440)
        foreach ([1.02, 1.0440, 1.06] as $r) {
            $this->calibrationService->recordObservation([
                'provider' => 'streetcars',
                'pickup' => 'Manchester Airport',
                'dropoff' => 'Piccadilly',
                'route_distance' => 9.5,
                'route_duration' => 25,
                'observed_real_world_fare' => 25.0 * $r,
                'estimated_fare' => 25.0,
                'trip_category' => 'airport_to_city',
            ]);
        }

        $config = $this->calibrationService->applyCalibration('streetcars', minSamples: 3);

        $multipliers = $config->config_data['category_calibration_multipliers'];
        $this->assertArrayHasKey('city_short', $multipliers);
        $this->assertArrayHasKey('airport_to_city', $multipliers);
        $this->assertEquals(0.6893, $multipliers['city_short']);
        $this->assertEquals(1.0440, $multipliers['airport_to_city']);
    }

    // E. Insufficient categories are skipped and existing values remain unchanged.
    public function test_e_insufficient_categories_are_skipped_and_existing_values_remain_unchanged(): void
    {
        $provider = Provider::where('slug', 'streetcars')->first();
        $config = ProviderPricingConfig::where('provider_id', $provider->id)->where('is_active', true)->first();
        $config->update([
            'config_data' => [
                'category_calibration_multipliers' => [
                    'city_short' => 0.6893,
                    'city_medium' => 0.7500, // Pre-existing calibration
                ],
            ],
        ]);

        // Add 3 samples for airport_to_city (ready)
        foreach ([1.03, 1.0440, 1.05] as $r) {
            $this->calibrationService->recordObservation([
                'provider' => 'streetcars',
                'pickup' => 'Manchester Airport',
                'dropoff' => 'Piccadilly',
                'route_distance' => 9.5,
                'route_duration' => 25,
                'observed_real_world_fare' => 25.0 * $r,
                'estimated_fare' => 25.0,
                'trip_category' => 'airport_to_city',
            ]);
        }

        // Add only 1 sample for city_medium (insufficient: 1/3)
        $this->calibrationService->recordObservation([
            'provider' => 'streetcars',
            'pickup' => 'Piccadilly',
            'dropoff' => 'Salford Quays',
            'route_distance' => 4.2,
            'route_duration' => 15,
            'observed_real_world_fare' => 12.0,
            'estimated_fare' => 15.0,
            'trip_category' => 'city_medium',
        ]);

        $updated = $this->calibrationService->applyCalibration('streetcars', minSamples: 3);

        $multipliers = $updated->config_data['category_calibration_multipliers'];
        $this->assertEquals(1.0440, $multipliers['airport_to_city']);
        // city_medium was insufficient, so its pre-existing 0.7500 must remain unchanged
        $this->assertEquals(0.7500, $multipliers['city_medium']);
        $this->assertEquals(0.6893, $multipliers['city_short']);
    }

    // F. Existing config_data keys are preserved.
    public function test_f_existing_config_data_keys_are_preserved(): void
    {
        $provider = Provider::where('slug', 'streetcars')->first();
        $config = ProviderPricingConfig::where('provider_id', $provider->id)->where('is_active', true)->first();
        $config->update([
            'config_data' => [
                'estimate_low_multiplier' => 0.92,
                'estimate_high_multiplier' => 1.08,
                'custom_metadata_tag' => 'preserve_me',
            ],
        ]);

        // Record 3 samples for city_short
        for ($i = 0; $i < 3; $i++) {
            $this->calibrationService->recordObservation([
                'provider' => 'streetcars',
                'pickup' => 'Piccadilly',
                'dropoff' => 'Arndale',
                'route_distance' => 0.8,
                'route_duration' => 5,
                'observed_real_world_fare' => 3.5,
                'estimated_fare' => 5.0,
                'trip_category' => 'city_short',
            ]);
        }

        $updated = $this->calibrationService->applyCalibration('streetcars', minSamples: 3, category: 'city_short');

        $this->assertEquals(0.92, $updated->config_data['estimate_low_multiplier']);
        $this->assertEquals(1.08, $updated->config_data['estimate_high_multiplier']);
        $this->assertEquals('preserve_me', $updated->config_data['custom_metadata_tag']);
        $this->assertArrayHasKey('category_calibration_multipliers', $updated->config_data);
    }

    // G. Other providers remain unchanged.
    public function test_g_other_providers_remain_unchanged(): void
    {
        // Record 3 samples for StreetCars
        for ($i = 0; $i < 3; $i++) {
            $this->calibrationService->recordObservation([
                'provider' => 'streetcars',
                'pickup' => 'Piccadilly',
                'dropoff' => 'Arndale',
                'route_distance' => 0.8,
                'route_duration' => 5,
                'observed_real_world_fare' => 3.5,
                'estimated_fare' => 5.0,
                'trip_category' => 'city_short',
            ]);
        }

        $this->calibrationService->applyCalibration('streetcars', minSamples: 3);

        foreach (['uber', 'bolt', 'veezu'] as $slug) {
            $p = Provider::where('slug', $slug)->first();
            $c = ProviderPricingConfig::where('provider_id', $p->id)->where('is_active', true)->first();
            $this->assertEquals(1.0000, $c->calibration_multiplier);
            $this->assertEmpty($c->config_data['category_calibration_multipliers'] ?? []);
        }
    }

    // H. Artisan command category apply flow
    public function test_h_artisan_command_category_apply_flow(): void
    {
        // 3 samples for city_short
        for ($i = 0; $i < 3; $i++) {
            $this->calibrationService->recordObservation([
                'provider' => 'streetcars',
                'pickup' => 'Piccadilly',
                'dropoff' => 'Arndale',
                'route_distance' => 0.8,
                'route_duration' => 5,
                'observed_real_world_fare' => 4.0,
                'estimated_fare' => 5.0,
                'trip_category' => 'city_short',
            ]);
        }

        $this->artisan('taxiscanner:calibrate streetcars --category=city_short --apply')
            ->assertSuccessful()
            ->expectsOutputToContain('city_short')
            ->expectsOutputToContain('Fallback / Global Multiplier')
            ->expectsOutputToContain('Calibration process finished.');

        $p = Provider::where('slug', 'streetcars')->first();
        $c = ProviderPricingConfig::where('provider_id', $p->id)->where('is_active', true)->first();
        $this->assertEquals(0.8000, $c->config_data['category_calibration_multipliers']['city_short']);
    }
}
