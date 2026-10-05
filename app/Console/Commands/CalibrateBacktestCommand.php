<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Pricing\DTOs\CalibrationBacktestReport;
use App\Domain\Pricing\Services\EstimateCalibrationService;
use Illuminate\Console\Command;

class CalibrateBacktestCommand extends Command
{
    protected $signature = 'taxiscanner:calibrate:backtest
                            {provider=streetcars : Provider slug (streetcars, uber, bolt, veezu)}
                            {--min-samples=3 : Minimum required observations for category recommendation}
                            {--geographic : Compare baseline, simple multiplier, and new empirical geographic model for airport_to_suburb}
                            {--range-coverage : Run read-only range-coverage backtest against price range goal}';

    protected $description = 'Perform a read-only calibration backtest evaluating accuracy before and after hypothetical calibration';

    public function handle(EstimateCalibrationService $calibrationService): int
    {
        $provider = strtolower((string) $this->argument('provider'));
        $minSamples = (int) $this->option('min-samples');
        $geographic = (bool) $this->option('geographic');
        $rangeCoverage = (bool) $this->option('range-coverage');

        if ($rangeCoverage) {
            $report = $calibrationService->runRangeCoverageBacktest($provider);
            $this->renderRangeCoverageReport($report);

            return self::SUCCESS;
        }

        if ($geographic) {
            $comparison = $calibrationService->runGeographicModelComparison($provider);
            $this->renderGeographicReport($comparison);

            return self::SUCCESS;
        }

        $this->info(sprintf('=== CALIBRATION BACKTEST REPORT: %s (READ-ONLY) ===', strtoupper($provider)));
        $this->comment('Evaluating confirmed real-world observations against recommended category multipliers.');
        $this->newLine();

        $report = $calibrationService->runBacktest($provider, minSamples: $minSamples);

        $this->renderReport($report);

        return self::SUCCESS;
    }

    public function renderReport(CalibrationBacktestReport $report): void
    {
        // 1. Individual observations table
        $this->info('--- Individual Observations ---');
        $individualRows = [];
        foreach ($report->observations as $obs) {
            $individualRows[] = [
                strtoupper($obs['provider']),
                $obs['trip_category'],
                mb_strimwidth($obs['pickup'], 0, 32, '...'),
                mb_strimwidth($obs['dropoff'], 0, 32, '...'),
                '£'.number_format($obs['real_fare'], 2),
                '£'.number_format($obs['original_estimate_midpoint'], 3),
                number_format($obs['recommended_multiplier'], 4),
                '£'.number_format($obs['hypothetical_calibrated_estimate'], 3),
                number_format($obs['error_before'], 2).'%',
                number_format($obs['error_after'], 2).'%',
                strtoupper($obs['outcome']),
            ];
        }

        $this->table(
            [
                'Provider',
                'Category',
                'Pickup',
                'Dropoff',
                'Real Fare',
                'Orig Midpoint',
                'Rec Multiplier',
                'Hypothetical Est',
                'Error Before',
                'Error After',
                'Outcome',
            ],
            $individualRows
        );

        $this->newLine();

        // 2. Category breakdown table
        $this->info('--- Trip Category Breakdown ---');
        $catRows = [];
        foreach ($report->categories as $cat) {
            $catRows[] = [
                $cat['category'],
                $cat['sample_count'],
                number_format($cat['recommended_multiplier'], 4),
                number_format($cat['average_error_before'], 2).'%',
                number_format($cat['average_error_after'], 2).'%',
                number_format($cat['median_error_before'], 2).'%',
                number_format($cat['median_error_after'], 2).'%',
                $cat['improved_count'],
                $cat['worsened_count'],
            ];
        }

        $this->table(
            [
                'Category',
                'Samples',
                'Rec Multiplier',
                'Avg Error Before',
                'Avg Error After',
                'Median Error Before',
                'Median Error After',
                'Improved',
                'Worsened',
            ],
            $catRows
        );

        $this->newLine();

        // 3. Global summary metrics
        $this->info('--- Global Calibration Backtest Summary ---');
        $this->line(sprintf('• Global average absolute error BEFORE calibration:  <comment>%.2f%%</comment>', $report->globalMeanErrorBefore));
        $this->line(sprintf('• Global average absolute error AFTER calibration:   <info>%.2f%%</info>', $report->globalMeanErrorAfter));
        $this->line(sprintf('• Global median absolute error BEFORE calibration:   <comment>%.2f%%</comment>', $report->globalMedianErrorBefore));
        $this->line(sprintf('• Global median absolute error AFTER calibration:    <info>%.2f%%</info>', $report->globalMedianErrorAfter));
        $this->line(sprintf('• Number of observations where error improved:       <info>%d</info>', $report->improvedCount));
        $this->line(sprintf('• Number of observations where error became worse:   <error>%d</error>', $report->worsenedCount));
        $this->line(sprintf('• Number of observations approximately unchanged:    <comment>%d</comment>', $report->unchangedCount));
        $this->newLine();

        $this->comment('Notice: This is a simulation/backtest only. Active production database pricing configs remain untouched.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function renderGeographicReport(array $data): void
    {
        $this->info(sprintf('=== AIRPORT_TO_SUBURB GEOGRAPHIC MODEL COMPARISON: %s ===', strtoupper((string) $data['provider'])));
        $this->warn((string) $data['in_sample_notice']);
        $this->newLine();

        $this->info(sprintf('--- Observations Breakdown (%d confirmed trips) ---', $data['total_observations']));

        $rows = [];
        foreach ($data['observations'] as $obs) {
            $rows[] = [
                $obs['id'],
                mb_strimwidth((string) $obs['dropoff'], 0, 26, '...'),
                number_format($obs['distance_miles'], 1).' mi',
                '£'.number_format($obs['real_fare'], 2),
                '£'.number_format($obs['base_estimate'], 2).' ('.number_format($obs['error_baseline'], 1).'%)',
                '£'.number_format($obs['pred_simple_mult'], 2).' ('.number_format($obs['error_simple_mult'], 1).'%)',
                $obs['matched_zone'],
                '£'.number_format($obs['pred_geographic'], 2).' ('.number_format($obs['error_geographic'], 1).'%)',
                strtoupper((string) $obs['outcome_geo_vs_base']),
            ];
        }

        $this->table(
            [
                'ID',
                'Dropoff',
                'Distance',
                'Real Fare',
                'Base (Err)',
                'Simple Mult (Err)',
                'Matched Zone',
                'Geographic (Err)',
                'Outcome',
            ],
            $rows
        );

        $this->newLine();

        $this->info('--- Comparative Model Summary ---');
        $summary = $data['summary'];
        $summaryRows = [];
        foreach ($summary as $m) {
            $summaryRows[] = [
                $m['name'],
                number_format($m['average_error'], 2).'%',
                number_format($m['median_error'], 2).'%',
                $m['improved'],
                $m['worsened'],
                $m['unchanged'],
            ];
        }

        $this->table(
            [
                'Model',
                'Avg Error',
                'Median Error',
                'Improved vs Base',
                'Worsened vs Base',
                'Unchanged',
            ],
            $summaryRows
        );

        $this->newLine();
        $this->comment('Production Safety: Production geographic calibration remains disabled by default.');
        $this->comment('No database configs or observations were altered.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function renderRangeCoverageReport(array $data): void
    {
        $this->info(sprintf('=== RANGE-COVERAGE CALIBRATION BACKTEST: %s (READ-ONLY) ===', strtoupper((string) $data['provider'])));
        $this->comment('Calibration Goal: Real provider fare falls INSIDE the estimated price range (min <= real <= max).');
        $this->comment(sprintf('Standard Range Band: Low multiplier %.2f, High multiplier %.2f (tolerance: ±5%%).', $data['low_multiplier'], $data['high_multiplier']));
        $this->newLine();

        // 1. Model Comparison Table
        $this->info(sprintf('--- Model Comparison Summary (%d Total Confirmed Trips) ---', $data['total_observations']));
        $modelRows = [];
        foreach ($data['models'] as $m) {
            $modelRows[] = [
                $m['name'],
                $m['total_observations'],
                $m['inside_count'],
                $m['outside_count'],
                number_format($m['coverage_pct'], 1).'%',
                '£'.number_format($m['average_miss_distance'], 2),
                '£'.number_format($m['max_miss_distance'], 2),
            ];
        }

        $this->table(
            ['Model', 'Total', 'Inside', 'Outside', 'Coverage %', 'Avg Miss (£)', 'Max Miss (£)'],
            $modelRows
        );
        $this->newLine();

        // 2. Specific Stockport Evaluation
        $this->info('--- Specific Stockport Observations Evaluation (£22 and £28) ---');
        $stockportRows = [];
        $modelsToCompare = ['current_production', 'candidate_stockport_1_2580', 'candidate_stockport_1_2614'];
        foreach ($modelsToCompare as $mKey) {
            if (! isset($data['models'][$mKey])) {
                continue;
            }
            $m = $data['models'][$mKey];
            foreach ($m['observations'] as $obs) {
                if (str_contains(strtolower((string) $obs['dropoff']), 'stockport')) {
                    $stockportRows[] = [
                        $m['name'],
                        $obs['id'],
                        mb_strimwidth((string) $obs['dropoff'], 0, 24, '...'),
                        '£'.number_format($obs['real_fare'], 2),
                        '£'.number_format($obs['base_midpoint'], 2),
                        number_format($obs['multiplier'], 4),
                        '£'.number_format($obs['calibrated_midpoint'], 2),
                        sprintf('£%.2f–£%.2f', $obs['est_min'], $obs['est_max']),
                        $obs['status'],
                        $obs['miss_distance'] > 0 ? '£'.number_format($obs['miss_distance'], 2) : '£0.00',
                    ];
                }
            }
        }

        $this->table(
            ['Model', 'Obs ID', 'Dropoff', 'Real Fare', 'Base Est', 'Mult', 'Calibrated', 'Estimated Range', 'Status', 'Miss Dist'],
            $stockportRows
        );
        $this->newLine();

        // 3. Category Breakdown for Recommended Model (Candidate A)
        $recModel = $data['models']['candidate_stockport_1_2580'] ?? $data['models']['current_production'];
        $this->info(sprintf('--- Trip Category Breakdown for %s ---', $recModel['name']));
        $catRows = [];
        foreach ($recModel['category_breakdown'] as $cat => $stats) {
            $catRows[] = [
                $cat,
                $stats['total'],
                $stats['inside'],
                $stats['outside'],
                number_format($stats['coverage_pct'], 1).'%',
            ];
        }

        $this->table(
            ['Trip Category', 'Total Trips', 'Inside Range', 'Outside Range', 'Coverage %'],
            $catRows
        );
        $this->newLine();

        $this->comment('Safety: Read-only simulation. Active production database pricing configs and observations remain untouched.');
    }
}
