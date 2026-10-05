<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Pricing\DTOs\CalibrationBacktestReport;
use App\Domain\Pricing\DTOs\CalibrationRecommendation;
use App\Domain\Taxi\Enums\TaxiProvider;
use App\Domain\Taxi\Models\CalibrationObservation;
use App\Domain\Taxi\Models\Provider;
use App\Domain\Taxi\Models\ProviderPricingConfig;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;

class EstimateCalibrationService
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Record a real-world observed fare sample against a TaxiScanner estimate.
     *
     * @param  array{
     *     provider: string|TaxiProvider,
     *     pickup: string,
     *     dropoff: string,
     *     route_distance: float,
     *     route_duration: int,
     *     observed_real_world_fare: float,
     *     estimated_fare: float,
     *     trip_category?: ?string,
     *     notes?: ?string,
     *     observed_at?: ?DateTimeInterface,
     *     metadata?: ?array
     * }  $data
     */
    public function recordObservation(array $data): CalibrationObservation
    {
        $providerSlug = $data['provider'] instanceof TaxiProvider
            ? $data['provider']->value
            : strtolower((string) $data['provider']);

        $provider = Provider::where('slug', $providerSlug)->first();
        if (! $provider) {
            throw new InvalidArgumentException(sprintf('Unknown provider slug "%s".', $providerSlug));
        }

        $observedFare = (float) $data['observed_real_world_fare'];
        $estimatedFare = (float) $data['estimated_fare'];

        if ($observedFare <= 0.0) {
            throw new InvalidArgumentException('Observed real-world fare must be greater than zero.');
        }

        if ($estimatedFare <= 0.0) {
            throw new InvalidArgumentException('Estimated fare must be greater than zero.');
        }

        // Calculate difference percentage and sample ratio (observed / estimated)
        $diffPercentage = round((($observedFare - $estimatedFare) / $estimatedFare) * 100, 2);
        $recommendedMultiplier = round($observedFare / $estimatedFare, 4);

        // Flag extreme anomalies (< 0.35x or > 2.80x) as outliers for initial safety
        $isOutlier = ($recommendedMultiplier < 0.35 || $recommendedMultiplier > 2.80);

        // Auto-classify trip category if not explicitly provided
        $category = $data['trip_category'] ?? null;
        if (empty($category) && ! empty($data['pickup']) && ! empty($data['dropoff'])) {
            $category = $this->classifyTripCategory(
                pickup: (string) $data['pickup'],
                dropoff: (string) $data['dropoff'],
                distanceMiles: (float) $data['route_distance'],
            );
        }

        $observation = CalibrationObservation::create([
            'provider_id' => $provider->id,
            'provider_slug' => $providerSlug,
            'pickup' => $data['pickup'],
            'dropoff' => $data['dropoff'],
            'route_distance' => (float) $data['route_distance'],
            'route_duration' => (int) $data['route_duration'],
            'observed_real_world_fare' => $observedFare,
            'estimated_fare' => $estimatedFare,
            'difference_percentage' => $diffPercentage,
            'recommended_multiplier' => $recommendedMultiplier,
            'observed_at' => $data['observed_at'] ?? now(),
            'trip_category' => $category,
            'notes' => $data['notes'] ?? null,
            'is_outlier' => $isOutlier,
            'metadata' => $data['metadata'] ?? null,
        ]);

        $this->logger->info('Recorded taxi calibration observation', [
            'provider' => $providerSlug,
            'observed_fare' => $observedFare,
            'estimated_fare' => $estimatedFare,
            'difference_pct' => $diffPercentage,
            'multiplier' => $recommendedMultiplier,
            'is_outlier' => $isOutlier,
        ]);

        return $observation;
    }

    /**
     * Compute the recommended calibration multiplier across multiple samples.
     * Uses outlier-resistant statistical calculation (IQR and median).
     */
    public function calculateRecommendedMultiplier(
        string|TaxiProvider $provider,
        ?string $category = null,
        int $minSamples = 3,
    ): CalibrationRecommendation {
        $providerSlug = $provider instanceof TaxiProvider ? $provider->value : strtolower((string) $provider);

        $query = CalibrationObservation::query()
            ->forProvider($providerSlug)
            ->inliers();

        if ($category !== null) {
            $query->where('trip_category', $category);
        }

        /** @var \Illuminate\Support\Collection<int, CalibrationObservation> $observations */
        $observations = $query->get();
        $sampleCount = $observations->count();

        // Get current active calibration multiplier from database
        $currentMultiplier = $this->getCurrentMultiplier($providerSlug, $category);
        $ratios = $observations->pluck('recommended_multiplier')->map(fn ($v) => (float) $v)->toArray();
        $sampleMean = ! empty($ratios) ? $this->calculateMean($ratios) : $currentMultiplier;
        $sampleMedian = ! empty($ratios) ? $this->calculateMedian($ratios) : $currentMultiplier;

        // Guard: Check if we have enough samples to safely calculate
        if ($sampleCount < $minSamples) {
            return new CalibrationRecommendation(
                provider: $providerSlug,
                sampleCount: $sampleCount,
                meanMultiplier: round($sampleMean, 4),
                medianMultiplier: round($sampleMedian, 4),
                recommendedMultiplier: $currentMultiplier,
                currentMultiplier: $currentMultiplier,
                minMultiplier: ! empty($ratios) ? round(min($ratios), 4) : $currentMultiplier,
                maxMultiplier: ! empty($ratios) ? round(max($ratios), 4) : $currentMultiplier,
                outliersExcludedCount: 0,
                hasEnoughSamples: false,
                category: $category,
                notes: sprintf(
                    'Insufficient observation samples (INSUFFICIENT SAMPLES: %d/%d required) to safely calibrate %s.',
                    $sampleCount,
                    $minSamples,
                    strtoupper($providerSlug)
                ),
            );
        }

        // Statistical Outlier-Resistant Filtering (Interquartile Range)
        $filterResult = $this->filterOutliersIQR($ratios);
        $inliers = $filterResult['inliers'];
        $outliersCount = $filterResult['outliers_count'];

        // Fallback to all ratios if IQR trimmed too aggressively
        $dataset = ! empty($inliers) ? $inliers : $ratios;

        $mean = $this->calculateMean($dataset);
        $median = $this->calculateMedian($dataset);

        // Bound recommended multiplier safely between 0.60 and 1.80 to prevent extreme changes
        $safeMultiplier = max(0.6000, min(1.8000, $median));

        return new CalibrationRecommendation(
            provider: $providerSlug,
            sampleCount: $sampleCount,
            meanMultiplier: round($mean, 4),
            medianMultiplier: round($median, 4),
            recommendedMultiplier: round($safeMultiplier, 4),
            currentMultiplier: $currentMultiplier,
            minMultiplier: round(min($dataset), 4),
            maxMultiplier: round(max($dataset), 4),
            outliersExcludedCount: $outliersCount,
            hasEnoughSamples: true,
            category: $category,
            notes: sprintf(
                'Calculated from %d samples (%d outliers excluded). Median ratio: %.4f.',
                count($dataset),
                $outliersCount,
                $median
            ),
        );
    }

    /**
     * Apply calibration multiplier to a provider's active pricing configuration.
     * Safely guarded against single-observation or unreviewed modifications.
     * Supports both category-specific and global multi-category calibration.
     */
    public function applyCalibration(
        string|TaxiProvider $provider,
        ?float $customMultiplier = null,
        int $minSamples = 3,
        ?string $category = null,
    ): ProviderPricingConfig {
        $providerSlug = $provider instanceof TaxiProvider ? $provider->value : strtolower((string) $provider);

        $providerModel = Provider::where('slug', $providerSlug)->first();
        if (! $providerModel) {
            throw new InvalidArgumentException(sprintf('Unknown provider "%s".', $providerSlug));
        }

        $config = ProviderPricingConfig::where('provider_id', $providerModel->id)
            ->where('is_active', true)
            ->first();

        if (! $config) {
            throw new RuntimeException(sprintf('No active pricing configuration found for provider "%s".', $providerSlug));
        }

        $configData = $config->config_data ?? [];
        $existingCategoryMultipliers = $configData['category_calibration_multipliers'] ?? [];
        $existingCategoryMeta = $configData['category_calibration_meta'] ?? [];

        // CASE 1: Explicit Category Application (e.g. --category=city_short)
        if ($category !== null) {
            if ($customMultiplier !== null) {
                if ($customMultiplier < 0.50 || $customMultiplier > 2.00) {
                    throw new InvalidArgumentException('Custom calibration multiplier must be bounded between 0.50 and 2.00.');
                }
                $newMultiplier = round($customMultiplier, 4);
                $sampleCount = null;
                $diffPct = null;
            } else {
                $recommendation = $this->calculateRecommendedMultiplier($providerSlug, category: $category, minSamples: $minSamples);

                if (! $recommendation->hasEnoughSamples) {
                    throw new RuntimeException(sprintf(
                        "Cannot calibrate %s for category '%s': minimum of %d observations required, but only %d recorded.",
                        strtoupper($providerSlug),
                        $category,
                        $minSamples,
                        $recommendation->sampleCount
                    ));
                }

                $newMultiplier = $recommendation->recommendedMultiplier;
                $sampleCount = $recommendation->sampleCount;
                $diffPct = $recommendation->multiplierDifferencePercentage();
            }

            $existingCategoryMultipliers[$category] = $newMultiplier;
            $existingCategoryMeta[$category] = [
                'sample_count' => $sampleCount,
                'recommended' => $newMultiplier,
                'diff_pct' => $diffPct,
                'calibrated_at' => now()->toIso8601String(),
            ];

            $configData['category_calibration_multipliers'] = $existingCategoryMultipliers;
            $configData['category_calibration_meta'] = $existingCategoryMeta;
            $configData['last_calibrated_at'] = now()->toIso8601String();

            // Note: Global scalar calibration_multiplier column remains unchanged as fallback
            $config->update([
                'config_data' => $configData,
            ]);

            $this->logger->info('Applied category-specific pricing calibration multiplier', [
                'provider' => $providerSlug,
                'category' => $category,
                'multiplier' => $newMultiplier,
                'sample_count' => $sampleCount,
            ]);

            return $config;
        }

        // CASE 2: Custom Multiplier without category (updates global scalar fallback)
        if ($customMultiplier !== null) {
            if ($customMultiplier < 0.50 || $customMultiplier > 2.00) {
                throw new InvalidArgumentException('Custom calibration multiplier must be bounded between 0.50 and 2.00.');
            }
            $newMultiplier = round($customMultiplier, 4);
            $oldMultiplier = (float) ($config->calibration_multiplier ?? 1.0);
            $configData['last_calibrated_at'] = now()->toIso8601String();
            $configData['previous_calibration_multiplier'] = $oldMultiplier;

            $config->update([
                'calibration_multiplier' => $newMultiplier,
                'config_data' => $configData,
            ]);

            return $config;
        }

        // CASE 3: General Application (no category specified)
        // 1. Calculate category recommendations
        $catRecs = $this->calculateCategoryRecommendations($providerSlug, minSamples: $minSamples);
        $globalRec = $this->calculateRecommendedMultiplier($providerSlug, minSamples: $minSamples);

        $appliedCategoryCount = 0;
        foreach ($catRecs as $catName => $catRec) {
            if ($catRec->hasEnoughSamples) {
                $existingCategoryMultipliers[$catName] = $catRec->recommendedMultiplier;
                $existingCategoryMeta[$catName] = [
                    'sample_count' => $catRec->sampleCount,
                    'recommended' => $catRec->recommendedMultiplier,
                    'diff_pct' => $catRec->multiplierDifferencePercentage(),
                    'calibrated_at' => now()->toIso8601String(),
                ];
                $appliedCategoryCount++;
            }
            // If NOT enough samples, do NOT overwrite or delete existing ready category multiplier!
        }

        // Safety guard: if neither global nor any category has enough samples, fail safely
        if ($appliedCategoryCount === 0 && ! $globalRec->hasEnoughSamples) {
            throw new RuntimeException(sprintf(
                'Cannot calibrate %s: minimum of %d observations required, but only %d recorded.',
                strtoupper($providerSlug),
                $minSamples,
                $globalRec->sampleCount
            ));
        }

        $configData['category_calibration_multipliers'] = $existingCategoryMultipliers;
        $configData['category_calibration_meta'] = $existingCategoryMeta;
        $configData['last_calibrated_at'] = now()->toIso8601String();

        // Update global fallback multiplier if global recommendation has enough samples
        $updatePayload = ['config_data' => $configData];
        if ($globalRec->hasEnoughSamples) {
            $updatePayload['calibration_multiplier'] = $globalRec->recommendedMultiplier;
            $configData['previous_calibration_multiplier'] = (float) ($config->calibration_multiplier ?? 1.0);
            $configData['calibration_sample_count'] = $globalRec->sampleCount;
            $updatePayload['config_data'] = $configData;
        }

        $config->update($updatePayload);

        $this->logger->info('Applied pricing calibration', [
            'provider' => $providerSlug,
            'categories_applied' => $appliedCategoryCount,
            'global_multiplier' => $globalRec->hasEnoughSamples ? $globalRec->recommendedMultiplier : null,
        ]);

        return $config;
    }

    /**
     * Retrieve observations for a given provider.
     *
     * @return Collection<int, CalibrationObservation>
     */
    public function getObservations(
        string|TaxiProvider $provider,
        ?string $category = null,
        int $limit = 50,
    ): Collection {
        $providerSlug = $provider instanceof TaxiProvider ? $provider->value : strtolower((string) $provider);

        $query = CalibrationObservation::query()
            ->forProvider($providerSlug)
            ->orderByDesc('observed_at')
            ->limit($limit);

        if ($category !== null) {
            $query->where('trip_category', $category);
        }

        return $query->get();
    }

    /**
     * Retrieve the current active calibration multiplier for a provider.
     * If category is specified, checks config_data.category_calibration_multipliers first,
     * then falls back to the scalar calibration_multiplier.
     */
    public function getCurrentMultiplier(string $providerSlug, ?string $category = null): float
    {
        $provider = Provider::where('slug', $providerSlug)->first();
        if (! $provider) {
            return 1.0;
        }

        $config = ProviderPricingConfig::where('provider_id', $provider->id)
            ->where('is_active', true)
            ->first();

        if (! $config) {
            return 1.0;
        }

        if ($category !== null) {
            $catMultipliers = $config->config_data['category_calibration_multipliers'] ?? [];
            if (isset($catMultipliers[$category])) {
                return (float) $catMultipliers[$category];
            }
            if ($category === 'city_long' || $category === 'inter_suburb') {
                return 1.0;
            }
        }

        return (float) ($config?->calibration_multiplier ?? 1.0);
    }

    /**
     * Calculate statistical median of an array of numbers.
     *
     * @param  list<float>  $numbers
     */
    public function calculateMedian(array $numbers): float
    {
        if (empty($numbers)) {
            return 1.0;
        }

        sort($numbers);
        $count = count($numbers);
        $mid = (int) floor($count / 2);

        if ($count % 2 === 0) {
            return ($numbers[$mid - 1] + $numbers[$mid]) / 2.0;
        }

        return (float) $numbers[$mid];
    }

    /**
     * Calculate statistical mean of an array of numbers.
     *
     * @param  list<float>  $numbers
     */
    public function calculateMean(array $numbers): float
    {
        if (empty($numbers)) {
            return 1.0;
        }

        return array_sum($numbers) / count($numbers);
    }

    /**
     * Outlier-resistant filter using Interquartile Range (IQR).
     * Discards extreme points outside [Q1 - 1.5*IQR, Q3 + 1.5*IQR].
     *
     * @param  list<float>  $numbers
     * @return array{inliers: list<float>, outliers_count: int}
     */
    public function filterOutliersIQR(array $numbers): array
    {
        if (count($numbers) < 4) {
            return ['inliers' => $numbers, 'outliers_count' => 0];
        }

        sort($numbers);
        $count = count($numbers);

        $q1Index = (int) floor($count * 0.25);
        $q3Index = (int) floor($count * 0.75);

        $q1 = $numbers[$q1Index];
        $q3 = $numbers[$q3Index];
        $iqr = $q3 - $q1;

        $lowerFence = max(0.40, $q1 - (1.5 * $iqr));
        $upperFence = min(2.50, $q3 + (1.5 * $iqr));

        $inliers = [];
        $outliersCount = 0;

        foreach ($numbers as $val) {
            if ($val >= $lowerFence && $val <= $upperFence) {
                $inliers[] = $val;
            } else {
                $outliersCount++;
            }
        }

        return [
            'inliers' => $inliers,
            'outliers_count' => $outliersCount,
        ];
    }

    /**
     * Compute recommended calibration multipliers for each distinct trip category for a provider.
     *
     * @return array<string, CalibrationRecommendation>
     */
    public function calculateCategoryRecommendations(
        string|TaxiProvider $provider,
        int $minSamples = 3,
    ): array {
        $providerSlug = $provider instanceof TaxiProvider ? $provider->value : strtolower((string) $provider);

        $categories = CalibrationObservation::query()
            ->forProvider($providerSlug)
            ->whereNotNull('trip_category')
            ->distinct()
            ->pluck('trip_category')
            ->sort()
            ->values()
            ->toArray();

        $recommendations = [];
        foreach ($categories as $category) {
            $recommendations[(string) $category] = $this->calculateRecommendedMultiplier(
                provider: $providerSlug,
                category: (string) $category,
                minSamples: $minSamples,
            );
        }

        return $recommendations;
    }

    /**
     * Classify a trip into standard calibration categories based on location names and distance.
     * Categories:
     *   - airport_to_city
     *   - city_to_airport
     *   - airport_to_suburb
     *   - city_short
     *   - city_medium
     *   - inter_suburb
     *   - city_long
     */
    public function classifyTripCategory(string $pickup, string $dropoff, float $distanceMiles): string
    {
        $classifier = new TripCategoryClassifier;

        return $classifier->classify($pickup, $dropoff, $distanceMiles);
    }

    /**
     * Run a read-only calibration backtest evaluating recorded observations
     * against currently recommended category calibration multipliers.
     */
    public function runBacktest(
        string|TaxiProvider $provider = 'streetcars',
        int $minSamples = 3,
    ): CalibrationBacktestReport {
        $providerSlug = $provider instanceof TaxiProvider ? $provider->value : strtolower((string) $provider);

        $categoryRecommendations = $this->calculateCategoryRecommendations($providerSlug, minSamples: $minSamples);
        $globalRecommendation = $this->calculateRecommendedMultiplier($providerSlug, minSamples: $minSamples);

        /** @var \Illuminate\Support\Collection<int, CalibrationObservation> $observations */
        $observations = CalibrationObservation::query()
            ->forProvider($providerSlug)
            ->inliers()
            ->orderBy('id')
            ->get();

        $individual = [];
        $allErrorsBefore = [];
        $allErrorsAfter = [];
        $categoryData = [];

        foreach ($observations as $obs) {
            $meta = $obs->metadata ?? [];
            $range = $meta['taxiscanner_estimate_range'] ?? null;
            $min = $range['min'] ?? null;
            $max = $range['max'] ?? null;

            if ($min !== null && $max !== null) {
                $estMidpoint = ((float) $min + (float) $max) / 2.0;
            } elseif (isset($meta['taxiscanner_estimate_midpoint'])) {
                $estMidpoint = (float) $meta['taxiscanner_estimate_midpoint'];
            } else {
                $estMidpoint = (float) $obs->estimated_fare;
            }

            $realFare = (float) $obs->observed_real_world_fare;
            $cat = (string) ($obs->trip_category ?? 'unclassified');

            $recMultiplier = $categoryRecommendations[$cat]->recommendedMultiplier
                ?? $globalRecommendation->recommendedMultiplier;

            $errorBefore = abs($estMidpoint - $realFare) / $realFare * 100.0;
            $hypotheticalCalibrated = $estMidpoint * $recMultiplier;
            $errorAfter = abs($hypotheticalCalibrated - $realFare) / $realFare * 100.0;

            $diff = round($errorAfter, 2) - round($errorBefore, 2);
            if ($diff < -0.01) {
                $outcome = 'improved';
            } elseif ($diff > 0.01) {
                $outcome = 'worsened';
            } else {
                $outcome = 'unchanged';
            }

            $individual[] = [
                'id' => $obs->id,
                'provider' => $obs->provider_slug,
                'trip_category' => $cat,
                'pickup' => $obs->pickup,
                'dropoff' => $obs->dropoff,
                'real_fare' => $realFare,
                'original_estimate_midpoint' => round($estMidpoint, 3),
                'recommended_multiplier' => round($recMultiplier, 4),
                'hypothetical_calibrated_estimate' => round($hypotheticalCalibrated, 3),
                'error_before' => round($errorBefore, 2),
                'error_after' => round($errorAfter, 2),
                'outcome' => $outcome,
            ];

            $allErrorsBefore[] = $errorBefore;
            $allErrorsAfter[] = $errorAfter;

            if (! isset($categoryData[$cat])) {
                $categoryData[$cat] = [
                    'category' => $cat,
                    'recommended_multiplier' => round($recMultiplier, 4),
                    'errors_before' => [],
                    'errors_after' => [],
                    'improved_count' => 0,
                    'worsened_count' => 0,
                    'unchanged_count' => 0,
                ];
            }

            $categoryData[$cat]['errors_before'][] = $errorBefore;
            $categoryData[$cat]['errors_after'][] = $errorAfter;
            if ($outcome === 'improved') {
                $categoryData[$cat]['improved_count']++;
            } elseif ($outcome === 'worsened') {
                $categoryData[$cat]['worsened_count']++;
            } else {
                $categoryData[$cat]['unchanged_count']++;
            }
        }

        ksort($categoryData);

        $categories = [];
        foreach ($categoryData as $cat => $data) {
            $categories[$cat] = [
                'category' => $cat,
                'sample_count' => count($data['errors_before']),
                'recommended_multiplier' => $data['recommended_multiplier'],
                'average_error_before' => round($this->calculateMean($data['errors_before']), 2),
                'average_error_after' => round($this->calculateMean($data['errors_after']), 2),
                'median_error_before' => round($this->calculateMedian($data['errors_before']), 2),
                'median_error_after' => round($this->calculateMedian($data['errors_after']), 2),
                'improved_count' => $data['improved_count'],
                'worsened_count' => $data['worsened_count'],
                'unchanged_count' => $data['unchanged_count'],
            ];
        }

        $improvedTotal = count(array_filter($individual, fn ($r) => $r['outcome'] === 'improved'));
        $worsenedTotal = count(array_filter($individual, fn ($r) => $r['outcome'] === 'worsened'));
        $unchangedTotal = count(array_filter($individual, fn ($r) => $r['outcome'] === 'unchanged'));

        return new CalibrationBacktestReport(
            provider: $providerSlug,
            totalObservations: count($individual),
            globalMeanErrorBefore: round($this->calculateMean($allErrorsBefore), 2),
            globalMeanErrorAfter: round($this->calculateMean($allErrorsAfter), 2),
            globalMedianErrorBefore: round($this->calculateMedian($allErrorsBefore), 2),
            globalMedianErrorAfter: round($this->calculateMedian($allErrorsAfter), 2),
            improvedCount: $improvedTotal,
            worsenedCount: $worsenedTotal,
            unchangedCount: $unchangedTotal,
            categories: $categories,
            observations: $individual,
        );
    }

    /**
     * Run a comparative backtest for StreetCars airport_to_suburb observations across:
     * Model A: Current Baseline (fallback multiplier 1.0000)
     * Model B: Existing Simple Multiplier approach (e.g. 1.1333 recommended multiplier)
     * Model C: New Empirical Geographic Model (IN-SAMPLE)
     *
     * @return array<string, mixed>
     */
    public function runGeographicModelComparison(
        string|TaxiProvider $provider = 'streetcars',
        ?StreetCarsGeographicAdjustmentService $geoService = null,
    ): array {
        $providerSlug = $provider instanceof TaxiProvider ? $provider->value : strtolower((string) $provider);
        $geoService = $geoService ?? app(StreetCarsGeographicAdjustmentService::class);

        /** @var \Illuminate\Support\Collection<int, CalibrationObservation> $observations */
        $observations = CalibrationObservation::query()
            ->forProvider($providerSlug)
            ->where('trip_category', 'airport_to_suburb')
            ->inliers()
            ->orderBy('id')
            ->get();

        $suburbRec = $this->calculateRecommendedMultiplier($providerSlug, 'airport_to_suburb', minSamples: 3);
        $simpleMultiplier = $suburbRec->recommendedMultiplier;

        $errorsA = [];
        $errorsB = [];
        $errorsC = [];

        $improvedB = 0;
        $worsenedB = 0;
        $unchangedB = 0;

        $improvedC = 0;
        $worsenedC = 0;
        $unchangedC = 0;

        $rows = [];

        foreach ($observations as $obs) {
            $meta = $obs->metadata ?? [];
            $range = $meta['taxiscanner_estimate_range'] ?? null;
            if ($range && isset($range['min'], $range['max'])) {
                $baseMidpoint = ((float) $range['min'] + (float) $range['max']) / 2.0;
            } elseif (isset($meta['taxiscanner_estimate_midpoint'])) {
                $baseMidpoint = (float) $meta['taxiscanner_estimate_midpoint'];
            } else {
                $baseMidpoint = (float) $obs->estimated_fare;
            }

            $realFare = (float) $obs->observed_real_world_fare;

            // Model A: Baseline
            $predA = $baseMidpoint;
            $errA = abs($predA - $realFare) / $realFare * 100.0;

            // Model B: Simple Multiplier
            $predB = round($baseMidpoint * $simpleMultiplier, 2);
            $errB = abs($predB - $realFare) / $realFare * 100.0;

            // Model C: Empirical Geographic Model
            $zoneMatch = $geoService->matchZone(
                address: (string) $obs->dropoff,
                query: (string) $obs->dropoff,
            );

            if ($zoneMatch !== null) {
                $predC = round($baseMidpoint * $zoneMatch->multiplier, 2);
                if ($zoneMatch->minimumFloor !== null) {
                    $predC = max($predC, $zoneMatch->minimumFloor);
                }
                if ($zoneMatch->fixedFare !== null) {
                    $predC = $zoneMatch->fixedFare;
                }
                $matchedZoneKey = $zoneMatch->zoneKey;
                $matchedZoneName = $zoneMatch->zoneName;
            } else {
                $predC = $baseMidpoint;
                $matchedZoneKey = 'fallback_unmatched';
                $matchedZoneName = 'Fallback (1.0000)';
            }
            $errC = abs($predC - $realFare) / $realFare * 100.0;

            // B vs A
            if (round($errB, 2) < round($errA, 2) - 0.01) {
                $improvedB++;
            } elseif (round($errB, 2) > round($errA, 2) + 0.01) {
                $worsenedB++;
            } else {
                $unchangedB++;
            }

            // C vs A
            if (round($errC, 2) < round($errA, 2) - 0.01) {
                $outcomeC = 'improved';
                $improvedC++;
            } elseif (round($errC, 2) > round($errA, 2) + 0.01) {
                $outcomeC = 'worsened';
                $worsenedC++;
            } else {
                $outcomeC = 'unchanged';
                $unchangedC++;
            }

            $errorsA[] = $errA;
            $errorsB[] = $errB;
            $errorsC[] = $errC;

            $rows[] = [
                'id' => $obs->id,
                'dropoff' => $obs->dropoff,
                'distance_miles' => (float) $obs->route_distance,
                'real_fare' => $realFare,
                'base_estimate' => round($baseMidpoint, 2),
                'error_baseline' => round($errA, 2),
                'pred_simple_mult' => $predB,
                'error_simple_mult' => round($errB, 2),
                'matched_zone' => $matchedZoneKey,
                'matched_zone_name' => $matchedZoneName,
                'pred_geographic' => round($predC, 2),
                'error_geographic' => round($errC, 2),
                'outcome_geo_vs_base' => $outcomeC,
            ];
        }

        return [
            'provider' => $providerSlug,
            'total_observations' => count($rows),
            'simple_multiplier_used' => $simpleMultiplier,
            'is_in_sample' => true,
            'in_sample_notice' => 'IMPORTANT: The existing 14 observations are IN-SAMPLE data. The geographic model parameters were calibrated against this dataset. Reported accuracy does not guarantee identical performance on unseen out-of-sample destinations.',
            'summary' => [
                'model_a_baseline' => [
                    'name' => 'Current Baseline (Fallback 1.0000)',
                    'average_error' => ! empty($errorsA) ? round($this->calculateMean($errorsA), 2) : 0.0,
                    'median_error' => ! empty($errorsA) ? round($this->calculateMedian($errorsA), 2) : 0.0,
                    'improved' => 0,
                    'worsened' => 0,
                    'unchanged' => count($errorsA),
                ],
                'model_b_simple_multiplier' => [
                    'name' => sprintf('Simple Category Multiplier (%.4f)', $simpleMultiplier),
                    'average_error' => ! empty($errorsB) ? round($this->calculateMean($errorsB), 2) : 0.0,
                    'median_error' => ! empty($errorsB) ? round($this->calculateMedian($errorsB), 2) : 0.0,
                    'improved' => $improvedB,
                    'worsened' => $worsenedB,
                    'unchanged' => $unchangedB,
                ],
                'model_c_geographic' => [
                    'name' => 'Empirical Geographic Model (IN-SAMPLE)',
                    'average_error' => ! empty($errorsC) ? round($this->calculateMean($errorsC), 2) : 0.0,
                    'median_error' => ! empty($errorsC) ? round($this->calculateMedian($errorsC), 2) : 0.0,
                    'improved' => $improvedC,
                    'worsened' => $worsenedC,
                    'unchanged' => $unchangedC,
                ],
            ],
            'observations' => $rows,
        ];
    }

    /**
     * Run a read-only range-coverage backtest evaluating whether real provider fares
     * fall inside the calibrated TaxiScanner estimated price range (estimated_min <= real_fare <= estimated_max).
     *
     * @param  array<string, float>|null  $candidateCategoryMultipliers
     * @param  array<string, float>|null  $candidateZoneMultipliers
     * @param  list<array<string, mixed>>|null  $additionalObservations
     * @return array<string, mixed>
     */
    public function runRangeCoverageBacktest(
        string|TaxiProvider $provider = 'streetcars',
        ?array $candidateCategoryMultipliers = null,
        ?array $candidateZoneMultipliers = null,
        ?array $additionalObservations = null,
        ?StreetCarsGeographicAdjustmentService $geoService = null,
    ): array {
        $providerSlug = $provider instanceof TaxiProvider ? $provider->value : strtolower((string) $provider);
        $geoService = $geoService ?? app(StreetCarsGeographicAdjustmentService::class);

        /** @var Provider|null $providerModel */
        $providerModel = Provider::where('slug', $providerSlug)->first();
        $activeConfig = $providerModel?->activePricingConfig;
        $productionCategoryMultipliers = (array) ($activeConfig?->config_data['category_calibration_multipliers'] ?? []);
        $lowMultiplier = (float) ($activeConfig?->config_data['estimate_low_multiplier'] ?? 0.95);
        $highMultiplier = (float) ($activeConfig?->config_data['estimate_high_multiplier'] ?? 1.05);
        $minFare = (float) ($activeConfig?->minimum_fare ?? 0.0);

        /** @var Collection<int, CalibrationObservation> $dbObservations */
        $dbObservations = CalibrationObservation::query()
            ->forProvider($providerSlug)
            ->inliers()
            ->orderBy('id')
            ->get();

        $allObs = [];
        foreach ($dbObservations as $obs) {
            $meta = $obs->metadata ?? [];
            $range = $meta['taxiscanner_estimate_range'] ?? null;
            if ($range && isset($range['min'], $range['max'])) {
                $baseMidpoint = ((float) $range['min'] + (float) $range['max']) / 2.0;
            } elseif (isset($meta['taxiscanner_estimate_midpoint'])) {
                $baseMidpoint = (float) $meta['taxiscanner_estimate_midpoint'];
            } else {
                $baseMidpoint = (float) $obs->estimated_fare;
            }

            $allObs[] = [
                'id' => (string) $obs->id,
                'trip_category' => (string) ($obs->trip_category ?? 'unclassified'),
                'pickup' => (string) $obs->pickup,
                'dropoff' => (string) $obs->dropoff,
                'real_fare' => (float) $obs->observed_real_world_fare,
                'base_midpoint' => $baseMidpoint,
                'route_distance' => (float) ($obs->route_distance ?? 0.0),
            ];
        }

        $hasStockport28 = false;
        foreach ($allObs as $o) {
            if (str_contains(strtolower($o['dropoff']), 'stockport') && abs($o['real_fare'] - 28.00) < 0.01) {
                $hasStockport28 = true;
                break;
            }
        }

        if (! $hasStockport28 && $additionalObservations === null) {
            $allObs[] = [
                'id' => 'STOCKPORT-28',
                'trip_category' => 'airport_to_suburb',
                'pickup' => 'Manchester Airport (MAN)',
                'dropoff' => 'Stockport, UK',
                'real_fare' => 28.00,
                'base_midpoint' => 21.61,
                'route_distance' => 8.38,
            ];
        } elseif ($additionalObservations !== null) {
            foreach ($additionalObservations as $add) {
                $allObs[] = $add;
            }
        }

        $modelConfigs = [
            'baseline' => [
                'name' => 'Baseline (Uncalibrated: 1.0000)',
                'use_category_multipliers' => false,
                'use_geographic' => false,
                'cat_multipliers' => [],
                'zone_multipliers' => [],
            ],
            'category_only' => [
                'name' => 'Category Multipliers Only (No Geo)',
                'use_category_multipliers' => true,
                'use_geographic' => false,
                'cat_multipliers' => $productionCategoryMultipliers,
                'zone_multipliers' => [],
            ],
            'current_production' => [
                'name' => 'Current Production (Category + Current Geo [Stockport 1.0945])',
                'use_category_multipliers' => true,
                'use_geographic' => true,
                'cat_multipliers' => $productionCategoryMultipliers,
                'zone_multipliers' => [],
            ],
            'candidate_stockport_1_2580' => [
                'name' => 'Candidate A: Current Production + Stockport 1.2580 (Recommended)',
                'use_category_multipliers' => true,
                'use_geographic' => true,
                'cat_multipliers' => $productionCategoryMultipliers,
                'zone_multipliers' => ['stockport' => 1.2580],
            ],
            'candidate_stockport_1_2614' => [
                'name' => 'Candidate B: Current Production + Stockport 1.2614 (Sum-Ratio)',
                'use_category_multipliers' => true,
                'use_geographic' => true,
                'cat_multipliers' => $productionCategoryMultipliers,
                'zone_multipliers' => ['stockport' => 1.2614],
            ],
        ];

        if ($candidateCategoryMultipliers !== null || $candidateZoneMultipliers !== null) {
            $modelConfigs['custom_candidate'] = [
                'name' => 'Custom Candidate Model',
                'use_category_multipliers' => true,
                'use_geographic' => true,
                'cat_multipliers' => $candidateCategoryMultipliers ?? $productionCategoryMultipliers,
                'zone_multipliers' => $candidateZoneMultipliers ?? [],
            ];
        }

        $evaluatedModels = [];

        foreach ($modelConfigs as $modelKey => $cfg) {
            $evaluatedModels[$modelKey] = $this->evaluateModelRangeCoverage(
                modelKey: $modelKey,
                modelName: $cfg['name'],
                observations: $allObs,
                useCategoryMultipliers: $cfg['use_category_multipliers'],
                useGeographic: $cfg['use_geographic'],
                categoryMultipliers: $cfg['cat_multipliers'],
                zoneMultiplierOverrides: $cfg['zone_multipliers'],
                lowMultiplier: $lowMultiplier,
                highMultiplier: $highMultiplier,
                minFare: $minFare,
                geoService: $geoService
            );
        }

        $stockportSummary = [];
        foreach ($evaluatedModels as $mKey => $mRes) {
            $stockportObsList = array_values(array_filter(
                $mRes['observations'],
                fn (array $r): bool => str_contains(strtolower($r['dropoff']), 'stockport')
            ));

            $stockportSummary[$mKey] = [
                'model_name' => $mRes['name'],
                'observations' => $stockportObsList,
            ];
        }

        return [
            'provider' => $providerSlug,
            'total_observations' => count($allObs),
            'low_multiplier' => $lowMultiplier,
            'high_multiplier' => $highMultiplier,
            'models' => $evaluatedModels,
            'stockport_summary' => $stockportSummary,
        ];
    }

    /**
     * Helper to evaluate range coverage for a given set of observations and calibration settings.
     *
     * @param  list<array<string, mixed>>  $observations
     * @param  array<string, float>  $categoryMultipliers
     * @param  array<string, float>  $zoneMultiplierOverrides
     * @return array<string, mixed>
     */
    private function evaluateModelRangeCoverage(
        string $modelKey,
        string $modelName,
        array $observations,
        bool $useCategoryMultipliers,
        bool $useGeographic,
        array $categoryMultipliers,
        array $zoneMultiplierOverrides,
        float $lowMultiplier,
        float $highMultiplier,
        float $minFare,
        StreetCarsGeographicAdjustmentService $geoService,
    ): array {
        $rows = [];
        $insideCount = 0;
        $outsideCount = 0;
        $missDistances = [];
        $byCat = [];

        foreach ($observations as $obs) {
            $cat = (string) $obs['trip_category'];
            $realFare = (float) $obs['real_fare'];
            $baseMidpoint = (float) $obs['base_midpoint'];

            $multiplier = 1.0;
            $floor = null;
            $zoneKey = null;

            if ($useCategoryMultipliers && isset($categoryMultipliers[$cat])) {
                $multiplier = (float) $categoryMultipliers[$cat];
            }

            if ($useGeographic && $cat === 'airport_to_suburb') {
                $matchedZone = $geoService->matchZone(
                    address: (string) $obs['dropoff'],
                    query: (string) $obs['dropoff']
                );

                if ($matchedZone !== null) {
                    $zoneKey = $matchedZone->zoneKey;
                    $multiplier = $zoneMultiplierOverrides[$zoneKey] ?? $matchedZone->multiplier;
                    $floor = $matchedZone->minimumFloor;
                } else {
                    $zoneKey = 'unmatched_fallback';
                    $multiplier = 1.0000;
                }
            }

            $point = round($baseMidpoint * $multiplier, 2);
            if ($floor !== null && $point < $floor) {
                $point = $floor;
            }
            if ($minFare > 0.0 && $point < $minFare) {
                $point = $minFare;
            }

            $minPrice = round($point * $lowMultiplier, 2);
            $maxPrice = round($point * $highMultiplier, 2);
            if ($minFare > 0.0) {
                $minPrice = max($minPrice, $minFare);
                $maxPrice = max($maxPrice, $minFare);
            }

            $isInside = ($realFare >= $minPrice && $realFare <= $maxPrice);
            $missDistance = 0.0;
            if (! $isInside) {
                $missDistance = $realFare < $minPrice ? round($minPrice - $realFare, 2) : round($realFare - $maxPrice, 2);
                $outsideCount++;
                $missDistances[] = $missDistance;
            } else {
                $insideCount++;
            }

            if (! isset($byCat[$cat])) {
                $byCat[$cat] = ['total' => 0, 'inside' => 0, 'outside' => 0];
            }
            $byCat[$cat]['total']++;
            if ($isInside) {
                $byCat[$cat]['inside']++;
            } else {
                $byCat[$cat]['outside']++;
            }

            $rows[] = [
                'id' => $obs['id'],
                'category' => $cat,
                'pickup' => $obs['pickup'],
                'dropoff' => $obs['dropoff'],
                'real_fare' => $realFare,
                'base_midpoint' => $baseMidpoint,
                'multiplier' => $multiplier,
                'zone' => $zoneKey,
                'calibrated_midpoint' => $point,
                'est_min' => $minPrice,
                'est_max' => $maxPrice,
                'is_inside' => $isInside,
                'status' => $isInside ? 'PASS' : 'FAIL',
                'miss_distance' => $missDistance,
            ];
        }

        $total = count($rows);
        $coveragePct = $total > 0 ? round(($insideCount / $total) * 100, 2) : 0.0;
        $avgMiss = count($missDistances) > 0 ? round(array_sum($missDistances) / count($missDistances), 2) : 0.0;
        $maxMiss = count($missDistances) > 0 ? max($missDistances) : 0.0;

        foreach ($byCat as $cKey => $cData) {
            $byCat[$cKey]['coverage_pct'] = $cData['total'] > 0
                ? round(($cData['inside'] / $cData['total']) * 100, 1)
                : 0.0;
        }

        return [
            'key' => $modelKey,
            'name' => $modelName,
            'total_observations' => $total,
            'inside_count' => $insideCount,
            'outside_count' => $outsideCount,
            'coverage_pct' => $coveragePct,
            'average_miss_distance' => $avgMiss,
            'max_miss_distance' => $maxMiss,
            'category_breakdown' => $byCat,
            'observations' => $rows,
        ];
    }
}
