<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Pricing\Services\EstimateCalibrationService;
use App\Domain\Taxi\Models\CalibrationObservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Log\NullLogger;
use Tests\TestCase;

class RangeCoverageBacktestTest extends TestCase
{
    use RefreshDatabase;

    private EstimateCalibrationService $calibrationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        // Clear observations for isolated testing
        CalibrationObservation::query()->delete();

        $this->calibrationService = new EstimateCalibrationService(
            logger: new NullLogger,
        );
    }

    public function test_range_coverage_backtest_evaluates_inside_and_outside_fares(): void
    {
        // Inside observation: Real £20.00, Base £20.00, Range £19.00 - £21.00
        $this->calibrationService->recordObservation([
            'provider' => 'streetcars',
            'pickup' => 'Manchester Airport (MAN)',
            'dropoff' => 'Manchester City Centre',
            'route_distance' => 9.0,
            'route_duration' => 20,
            'observed_real_world_fare' => 20.00,
            'estimated_fare' => 20.00,
            'trip_category' => 'airport_to_city',
            'metadata' => [
                'taxiscanner_estimate_range' => ['min' => 19.00, 'max' => 21.00, 'midpoint' => 20.00],
            ],
        ]);

        // Outside observation: Real £30.00, Base £20.00, Range £19.00 - £21.00 (misses by £9.00)
        $this->calibrationService->recordObservation([
            'provider' => 'streetcars',
            'pickup' => 'Manchester Airport (MAN)',
            'dropoff' => 'Manchester Piccadilly',
            'route_distance' => 9.0,
            'route_duration' => 20,
            'observed_real_world_fare' => 30.00,
            'estimated_fare' => 20.00,
            'trip_category' => 'airport_to_city',
            'metadata' => [
                'taxiscanner_estimate_range' => ['min' => 19.00, 'max' => 21.00, 'midpoint' => 20.00],
            ],
        ]);

        $report = $this->calibrationService->runRangeCoverageBacktest(
            provider: 'streetcars',
            additionalObservations: [] // exclude default stockport simulated for this isolated test
        );

        $baseline = $report['models']['baseline'];
        $this->assertSame(2, $baseline['total_observations']);
        $this->assertSame(1, $baseline['inside_count']);
        $this->assertSame(1, $baseline['outside_count']);
        $this->assertSame(50.0, $baseline['coverage_pct']);
        $this->assertSame(9.00, $baseline['average_miss_distance']);
    }

    public function test_stockport_candidate_multiplier_covers_both_22_and_28_fares(): void
    {
        // Obs 1: Stockport Town Centre (base £18.03, real £22.00)
        $this->calibrationService->recordObservation([
            'provider' => 'streetcars',
            'pickup' => 'Manchester Airport (MAN)',
            'dropoff' => 'Stockport Town Centre, Stockport, UK',
            'route_distance' => 6.52,
            'route_duration' => 11,
            'observed_real_world_fare' => 22.00,
            'estimated_fare' => 18.03,
            'trip_category' => 'airport_to_suburb',
            'metadata' => [
                'taxiscanner_estimate_range' => ['min' => 17.13, 'max' => 18.93, 'midpoint' => 18.03],
            ],
        ]);

        // Obs 2: Stockport (base £21.61, real £28.00)
        $stockport2 = [
            'id' => 'STOCKPORT-28',
            'trip_category' => 'airport_to_suburb',
            'pickup' => 'Manchester Airport (MAN)',
            'dropoff' => 'Stockport',
            'real_fare' => 28.00,
            'base_midpoint' => 21.61,
            'route_distance' => 8.38,
        ];

        $report = $this->calibrationService->runRangeCoverageBacktest(
            provider: 'streetcars',
            additionalObservations: [$stockport2]
        );

        // Under Current Production (stockport = 1.2580)
        $prodStockport = $report['stockport_summary']['current_production']['observations'];
        $this->assertCount(2, $prodStockport);
        $this->assertSame('PASS', $prodStockport[0]['status']); // £22.00 in [£21.55, £23.81]
        $this->assertSame('PASS', $prodStockport[1]['status']); // £28.00 in [£25.83, £28.55]
        $this->assertSame(0.0, $prodStockport[0]['miss_distance']);
        $this->assertSame(0.0, $prodStockport[1]['miss_distance']);

        // Under Candidate A (stockport = 1.2580)
        $candAStockport = $report['stockport_summary']['candidate_stockport_1_2580']['observations'];
        $this->assertCount(2, $candAStockport);
        $this->assertSame('PASS', $candAStockport[0]['status']); // £22.00 in [£21.55, £23.81]
        $this->assertSame('PASS', $candAStockport[1]['status']); // £28.00 in [£25.83, £28.55]
        $this->assertSame(0.0, $candAStockport[0]['miss_distance']);
        $this->assertSame(0.0, $candAStockport[1]['miss_distance']);
    }

    public function test_artisan_command_range_coverage_executes_successfully(): void
    {
        $this->artisan('taxiscanner:calibrate:backtest --range-coverage')
            ->assertSuccessful()
            ->expectsOutputToContain('RANGE-COVERAGE CALIBRATION BACKTEST: STREETCARS')
            ->expectsOutputToContain('Candidate A: Current Production + Stockport 1.2580')
            ->expectsOutputToContain('Stockport');
    }
}
