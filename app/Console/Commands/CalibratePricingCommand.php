<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Pricing\Services\EstimateCalibrationService;
use App\Domain\Taxi\Enums\TaxiProvider;
use Illuminate\Console\Command;

class CalibratePricingCommand extends Command
{
    protected $signature = 'taxiscanner:calibrate
                            {provider? : Optional specific provider slug (uber, bolt, streetcars, veezu)}
                            {--category= : Optional trip category (e.g. city_short, airport_to_city, etc.)}
                            {--apply : Automatically apply recommended calibration multiplier to active provider pricing}
                            {--backtest : Run read-only calibration backtest evaluating accuracy before and after hypothetical calibration}
                            {--min-samples=3 : Minimum required observations before calibration can be applied}';

    protected $description = 'Analyze real-world observation samples and calculate or apply recommended calibration multipliers';

    public function handle(EstimateCalibrationService $calibrationService): int
    {
        $providerInput = $this->argument('provider');
        $categoryInput = $this->option('category') ?: null;
        $apply = (bool) $this->option('apply');
        $backtest = (bool) $this->option('backtest');
        $minSamples = (int) $this->option('min-samples');

        if ($backtest) {
            $slug = $providerInput ? strtolower((string) $providerInput) : 'streetcars';

            return $this->call('taxiscanner:calibrate:backtest', [
                'provider' => $slug,
                '--min-samples' => $minSamples,
            ]);
        }

        $providers = $providerInput
            ? [strtolower((string) $providerInput)]
            : array_map(fn (TaxiProvider $p) => $p->value, TaxiProvider::cases());

        $rows = [];
        $categoryRows = [];
        $appliedSummary = [];
        $skippedSummary = [];

        foreach ($providers as $slug) {
            $fallbackMultiplier = $calibrationService->getCurrentMultiplier($slug);

            if ($categoryInput !== null) {
                // Targeted single category calibration
                $recommendation = $calibrationService->calculateRecommendedMultiplier(
                    provider: $slug,
                    category: $categoryInput,
                    minSamples: $minSamples,
                );

                $previousMultiplier = $calibrationService->getCurrentMultiplier($slug, $categoryInput);
                $isApplied = false;
                $status = $recommendation->hasEnoughSamples ? 'READY' : 'INSUFFICIENT SAMPLES';

                if ($apply) {
                    if ($recommendation->hasEnoughSamples) {
                        try {
                            $calibrationService->applyCalibration($slug, minSamples: $minSamples, category: $categoryInput);
                            $status = 'APPLIED ('.number_format($recommendation->recommendedMultiplier, 4).')';
                            $isApplied = true;
                            $appliedSummary[] = strtoupper($slug).' -> '.$categoryInput.' ('.number_format($recommendation->recommendedMultiplier, 4).')';
                        } catch (\Throwable $e) {
                            $status = 'ERROR: '.$e->getMessage();
                        }
                    } else {
                        $status = 'SKIPPED (INSUFFICIENT SAMPLES)';
                        $skippedSummary[] = strtoupper($slug).' -> '.$categoryInput.' ('.$recommendation->sampleCount.'/'.$minSamples.')';
                    }
                }

                $diffPct = $recommendation->multiplierDifferencePercentage();
                $diffFormatted = ($diffPct > 0 ? '+' : '').$diffPct.'%';

                $rows[] = [
                    strtoupper($slug),
                    $categoryInput,
                    $recommendation->sampleCount,
                    number_format($previousMultiplier, 4),
                    number_format($recommendation->meanMultiplier, 4),
                    number_format($recommendation->medianMultiplier, 4),
                    number_format($recommendation->recommendedMultiplier, 4),
                    $diffFormatted,
                    $recommendation->outliersExcludedCount,
                    $apply ? ($isApplied ? 'YES' : 'NO') : 'DRY-RUN',
                    $status,
                ];
            } else {
                // Multi-category & Global Fallback
                $globalRec = $calibrationService->calculateRecommendedMultiplier(
                    provider: $slug,
                    category: null,
                    minSamples: $minSamples,
                );

                $catRecs = $calibrationService->calculateCategoryRecommendations($slug, minSamples: $minSamples);

                $globalApplied = false;
                $globalStatus = $globalRec->hasEnoughSamples ? 'READY' : 'INSUFFICIENT SAMPLES';

                if ($apply) {
                    try {
                        $calibrationService->applyCalibration($slug, minSamples: $minSamples);
                        $globalStatus = 'APPLIED ('.number_format($globalRec->recommendedMultiplier, 4).')';
                        $globalApplied = true;
                    } catch (\Throwable $e) {
                        $globalStatus = 'ERROR: '.$e->getMessage();
                    }
                }

                $diffPct = $globalRec->multiplierDifferencePercentage();
                $diffFormatted = ($diffPct > 0 ? '+' : '').$diffPct.'%';

                $rows[] = [
                    strtoupper($slug),
                    'ALL (Global Fallback)',
                    $globalRec->sampleCount,
                    number_format($fallbackMultiplier, 4),
                    number_format($globalRec->meanMultiplier, 4),
                    number_format($globalRec->medianMultiplier, 4),
                    number_format($globalRec->recommendedMultiplier, 4),
                    $diffFormatted,
                    $globalRec->outliersExcludedCount,
                    $apply ? ($globalApplied ? 'YES' : 'NO') : 'DRY-RUN',
                    $globalStatus,
                ];

                foreach ($catRecs as $catName => $catRec) {
                    $catPrevMultiplier = $calibrationService->getCurrentMultiplier($slug, $catName);
                    $catDiff = $catRec->multiplierDifferencePercentage();
                    $catDiffFormatted = ($catDiff > 0 ? '+' : '').$catDiff.'%';

                    if ($apply) {
                        if ($catRec->hasEnoughSamples) {
                            $catStatus = 'APPLIED ('.number_format($catRec->recommendedMultiplier, 4).')';
                            $catAppliedCol = 'YES';
                            $appliedSummary[] = strtoupper($slug).' -> '.$catName.' ('.number_format($catRec->recommendedMultiplier, 4).')';
                        } else {
                            $catStatus = 'SKIPPED (INSUFFICIENT SAMPLES)';
                            $catAppliedCol = 'NO';
                            $skippedSummary[] = strtoupper($slug).' -> '.$catName.' ('.$catRec->sampleCount.'/'.$minSamples.')';
                        }
                    } else {
                        $catStatus = $catRec->hasEnoughSamples ? 'READY' : 'INSUFFICIENT SAMPLES';
                        $catAppliedCol = 'DRY-RUN';
                        if (! $catRec->hasEnoughSamples) {
                            $skippedSummary[] = strtoupper($slug).' -> '.$catName.' ('.$catRec->sampleCount.'/'.$minSamples.')';
                        }
                    }

                    $categoryRows[] = [
                        strtoupper($slug),
                        $catName,
                        $catRec->sampleCount,
                        number_format($catPrevMultiplier, 4),
                        number_format($catRec->meanMultiplier, 4),
                        number_format($catRec->medianMultiplier, 4),
                        number_format($catRec->recommendedMultiplier, 4),
                        $catDiffFormatted,
                        $catRec->outliersExcludedCount,
                        $catAppliedCol,
                        $catStatus,
                    ];
                }
            }
        }

        $headers = ['Provider', 'Scope', 'Samples', 'Previous', 'Mean', 'Median', 'Recommended', 'Diff %', 'Outliers', 'Applied?', 'Status'];
        $this->table($headers, $rows);

        if (! empty($categoryRows)) {
            $this->newLine();
            $this->info('--- Trip Category Breakdown ---');
            $categoryHeaders = ['Provider', 'Category', 'Samples', 'Previous', 'Mean', 'Median', 'Recommended', 'Diff %', 'Outliers', 'Applied?', 'Status'];
            $this->table($categoryHeaders, $categoryRows);
        }

        $this->newLine();
        foreach ($providers as $slug) {
            $currentGlobal = $calibrationService->getCurrentMultiplier($slug);
            $this->line(sprintf('• <comment>%s</comment> Fallback / Global Multiplier: <info>%.4f</info>', strtoupper($slug), $currentGlobal));
        }

        if (! empty($appliedSummary)) {
            $this->line('• Categories Applied: <info>'.implode(', ', $appliedSummary).'</info>');
        }

        if (! empty($skippedSummary)) {
            $this->line('• Categories Skipped (Insufficient Samples): <comment>'.implode(', ', $skippedSummary).'</comment>');
        }

        if (! $apply) {
            $this->info('Dry-run calculation completed. Use --apply to update active database pricing configs.');
        } else {
            $this->info('Calibration process finished.');
        }

        return self::SUCCESS;
    }
}
