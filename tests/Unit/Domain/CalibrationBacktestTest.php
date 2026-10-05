<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Pricing\DTOs\CalibrationBacktestReport;
use App\Domain\Pricing\Services\EstimateCalibrationService;
use App\Domain\Taxi\Models\CalibrationObservation;
use App\Domain\Taxi\Models\Provider;
use App\Domain\Taxi\Models\ProviderPricingConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Log\NullLogger;
use Tests\TestCase;

class CalibrationBacktestTest extends TestCase
{
    use RefreshDatabase;

    private EstimateCalibrationService $calibrationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        // Clear observations so tests have explicit isolated data
        CalibrationObservation::query()->delete();

        $this->calibrationService = new EstimateCalibrationService(
            logger: new NullLogger,
        );
    }

    public function test_backtest_calculates_midpoint_error_and_hypothetical_calibrated_fare(): void
    {
        // Category: city_short (3 samples required for calibration)
        // Sample 1: min £5.00, max £6.00 (mid £5.50), real £4.00 => ratio: 4.00 / 5.50 = 0.7273
        $this->calibrationService->recordObservation([
            'provider' => 'streetcars',
            'pickup' => 'Piccadilly',
            'dropoff' => 'Arndale',
            'route_distance' => 0.8,
            'route_duration' => 5,
            'observed_real_world_fare' => 4.00,
            'estimated_fare' => 5.50,
            'trip_category' => 'city_short',
            'metadata' => [
                'taxiscanner_estimate_range' => ['min' => 5.00, 'max' => 6.00, 'midpoint' => 5.50],
            ],
        ]);

        // Sample 2: min £7.00, max £9.00 (mid £8.00), real £6.00 => ratio: 6.00 / 8.00 = 0.7500
        $this->calibrationService->recordObservation([
            'provider' => 'streetcars',
            'pickup' => 'Piccadilly',
            'dropoff' => 'Old Trafford',
            'route_distance' => 3.5,
            'route_duration' => 12,
            'observed_real_world_fare' => 6.00,
            'estimated_fare' => 8.00,
            'trip_category' => 'city_short',
            'metadata' => [
                'taxiscanner_estimate_range' => ['min' => 7.00, 'max' => 9.00, 'midpoint' => 8.00],
            ],
        ]);

        // Sample 3: min £9.00, max £11.00 (mid £10.00), real £7.00 => ratio: 7.00 / 10.00 = 0.7000
        $this->calibrationService->recordObservation([
            'provider' => 'streetcars',
            'pickup' => 'Piccadilly',
            'dropoff' => 'Salford',
            'route_distance' => 2.5,
            'route_duration' => 10,
            'observed_real_world_fare' => 7.00,
            'estimated_fare' => 10.00,
            'trip_category' => 'city_short',
            'metadata' => [
                'taxiscanner_estimate_range' => ['min' => 9.00, 'max' => 11.00, 'midpoint' => 10.00],
            ],
        ]);

        $report = $this->calibrationService->runBacktest('streetcars', minSamples: 3);

        $this->assertInstanceOf(CalibrationBacktestReport::class, $report);
        $this->assertEquals(3, $report->totalObservations);

        // Recommended multiplier: median of [0.7000, 0.7273, 0.7500] = 0.7273
        $catData = $report->categories['city_short'];
        $this->assertEquals(0.7273, $catData['recommended_multiplier']);

        // Check first observation details
        $obs1 = $report->observations[0];
        $this->assertEquals(5.50, $obs1['original_estimate_midpoint']);
        $this->assertEquals(4.00, $obs1['real_fare']);
        // Error before: abs(5.50 - 4.00) / 4.00 * 100 = 37.50%
        $this->assertEquals(37.50, $obs1['error_before']);
        // Hypothetical calibrated estimate: 5.50 * 0.7273 = 4.00015 => 4.000
        $this->assertEquals(round(5.50 * 0.7273, 3), $obs1['hypothetical_calibrated_estimate']);
        // Error after: abs(4.00015 - 4.00) / 4.00 * 100 = ~0.00%
        $this->assertLessThan(1.0, $obs1['error_after']);
        $this->assertEquals('improved', $obs1['outcome']);
    }

    public function test_backtest_distinguishes_improved_and_worsened_observations(): void
    {
        // 3 samples to form recommended multiplier
        // Ratios: 1.20, 1.20, 0.90 => median is 1.20
        $this->calibrationService->recordObservation([
            'provider' => 'streetcars',
            'pickup' => 'A',
            'dropoff' => 'B',
            'route_distance' => 5.0,
            'route_duration' => 10,
            'observed_real_world_fare' => 24.00,
            'estimated_fare' => 20.00, // 24 / 20 = 1.20. Error before: 16.67%. After: 20 * 1.20 = 24 => 0.00% (improved)
            'trip_category' => 'city_medium',
        ]);

        $this->calibrationService->recordObservation([
            'provider' => 'streetcars',
            'pickup' => 'C',
            'dropoff' => 'D',
            'route_distance' => 5.0,
            'route_duration' => 10,
            'observed_real_world_fare' => 24.00,
            'estimated_fare' => 20.00, // 24 / 20 = 1.20 (improved)
            'trip_category' => 'city_medium',
        ]);

        $this->calibrationService->recordObservation([
            'provider' => 'streetcars',
            'pickup' => 'E',
            'dropoff' => 'F',
            'route_distance' => 5.0,
            'route_duration' => 10,
            'observed_real_world_fare' => 18.00,
            'estimated_fare' => 20.00, // 18 / 20 = 0.90. Error before: |20 - 18|/18 = 11.11%. After: 20 * 1.20 = 24 => |24 - 18|/18 = 33.33% (worsened)
            'trip_category' => 'city_medium',
        ]);

        $report = $this->calibrationService->runBacktest('streetcars', minSamples: 3);

        $this->assertEquals(2, $report->improvedCount);
        $this->assertEquals(1, $report->worsenedCount);
        $this->assertEquals(0, $report->unchangedCount);

        $this->assertEquals(2, $report->categories['city_medium']['improved_count']);
        $this->assertEquals(1, $report->categories['city_medium']['worsened_count']);
    }

    public function test_backtest_is_strictly_read_only_and_preserves_production_pricing(): void
    {
        $provider = Provider::where('slug', 'streetcars')->first();
        $config = ProviderPricingConfig::where('provider_id', $provider->id)->where('is_active', true)->first();
        $this->assertEquals(1.0000, $config->calibration_multiplier);

        // Record 3 samples
        for ($i = 0; $i < 3; $i++) {
            $this->calibrationService->recordObservation([
                'provider' => 'streetcars',
                'pickup' => 'A',
                'dropoff' => 'B',
                'route_distance' => 5.0,
                'route_duration' => 10,
                'observed_real_world_fare' => 30.00,
                'estimated_fare' => 20.00,
                'trip_category' => 'city_short',
            ]);
        }

        // Run backtest
        $report = $this->calibrationService->runBacktest('streetcars', minSamples: 3);
        $this->assertNotEmpty($report->observations);

        // Verify database config has NOT been modified
        $freshConfig = ProviderPricingConfig::where('provider_id', $provider->id)->where('is_active', true)->first();
        $this->assertEquals(1.0000, $freshConfig->calibration_multiplier);

        foreach (['uber', 'bolt', 'veezu'] as $otherSlug) {
            $otherProv = Provider::where('slug', $otherSlug)->first();
            $otherConfig = ProviderPricingConfig::where('provider_id', $otherProv->id)->where('is_active', true)->first();
            $this->assertEquals(1.0000, $otherConfig->calibration_multiplier);
        }
    }

    public function test_artisan_commands_execute_backtest_successfully(): void
    {
        // Add 3 samples
        for ($i = 0; $i < 3; $i++) {
            $this->calibrationService->recordObservation([
                'provider' => 'streetcars',
                'pickup' => 'Piccadilly',
                'dropoff' => 'Manchester Airport',
                'route_distance' => 9.0,
                'route_duration' => 25,
                'observed_real_world_fare' => 26.00,
                'estimated_fare' => 24.00,
                'trip_category' => 'city_to_airport',
            ]);
        }

        // 1. Direct backtest command
        $this->artisan('taxiscanner:calibrate:backtest streetcars')
            ->assertSuccessful()
            ->expectsOutputToContain('CALIBRATION BACKTEST REPORT: STREETCARS (READ-ONLY)')
            ->expectsOutputToContain('Global Calibration Backtest Summary')
            ->expectsOutputToContain('Global average absolute error BEFORE calibration')
            ->expectsOutputToContain('Global average absolute error AFTER calibration');

        // 2. Calibrate command with --backtest option
        $this->artisan('taxiscanner:calibrate streetcars --backtest')
            ->assertSuccessful()
            ->expectsOutputToContain('CALIBRATION BACKTEST REPORT: STREETCARS (READ-ONLY)');
    }
}
