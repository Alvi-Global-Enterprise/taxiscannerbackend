<?php

declare(strict_types=1);

namespace App\Domain\Pricing\DTOs;

use App\Domain\Taxi\Enums\TaxiProvider;

final readonly class CalibrationRecommendation
{
    public function __construct(
        public TaxiProvider|string $provider,
        public int $sampleCount,
        public float $meanMultiplier,
        public float $medianMultiplier,
        public float $recommendedMultiplier,
        public float $currentMultiplier,
        public float $minMultiplier,
        public float $maxMultiplier,
        public int $outliersExcludedCount = 0,
        public bool $hasEnoughSamples = true,
        public ?string $category = null,
        public ?string $notes = null,
    ) {}

    public function providerSlug(): string
    {
        return $this->provider instanceof TaxiProvider ? $this->provider->value : (string) $this->provider;
    }

    public function multiplierDifference(): float
    {
        return round($this->recommendedMultiplier - $this->currentMultiplier, 4);
    }

    public function multiplierDifferencePercentage(): float
    {
        if ($this->currentMultiplier <= 0.0) {
            return 0.0;
        }

        return round((($this->recommendedMultiplier - $this->currentMultiplier) / $this->currentMultiplier) * 100, 2);
    }

    public function toArray(): array
    {
        return [
            'provider' => $this->providerSlug(),
            'sample_count' => $this->sampleCount,
            'mean_multiplier' => $this->meanMultiplier,
            'median_multiplier' => $this->medianMultiplier,
            'recommended_multiplier' => $this->recommendedMultiplier,
            'current_multiplier' => $this->currentMultiplier,
            'min_multiplier' => $this->minMultiplier,
            'max_multiplier' => $this->maxMultiplier,
            'outliers_excluded_count' => $this->outliersExcludedCount,
            'has_enough_samples' => $this->hasEnoughSamples,
            'category' => $this->category,
            'difference_percentage' => $this->multiplierDifferencePercentage(),
            'notes' => $this->notes,
        ];
    }
}
