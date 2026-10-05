<?php

declare(strict_types=1);

namespace App\Domain\Pricing\DTOs;

final readonly class CalibrationBacktestReport
{
    /**
     * @param  array<string, array{
     *     category: string,
     *     sample_count: int,
     *     recommended_multiplier: float,
     *     average_error_before: float,
     *     average_error_after: float,
     *     median_error_before: float,
     *     median_error_after: float,
     *     improved_count: int,
     *     worsened_count: int,
     *     unchanged_count: int
     * }>  $categories
     * @param  list<array{
     *     id: int,
     *     provider: string,
     *     trip_category: string,
     *     pickup: string,
     *     dropoff: string,
     *     real_fare: float,
     *     original_estimate_midpoint: float,
     *     recommended_multiplier: float,
     *     hypothetical_calibrated_estimate: float,
     *     error_before: float,
     *     error_after: float,
     *     outcome: string
     * }>  $observations
     */
    public function __construct(
        public string $provider,
        public int $totalObservations,
        public float $globalMeanErrorBefore,
        public float $globalMeanErrorAfter,
        public float $globalMedianErrorBefore,
        public float $globalMedianErrorAfter,
        public int $improvedCount,
        public int $worsenedCount,
        public int $unchangedCount,
        public array $categories,
        public array $observations,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'total_observations' => $this->totalObservations,
            'global_mean_error_before' => $this->globalMeanErrorBefore,
            'global_mean_error_after' => $this->globalMeanErrorAfter,
            'global_median_error_before' => $this->globalMedianErrorBefore,
            'global_median_error_after' => $this->globalMedianErrorAfter,
            'improved_count' => $this->improvedCount,
            'worsened_count' => $this->worsenedCount,
            'unchanged_count' => $this->unchangedCount,
            'categories' => $this->categories,
            'observations' => $this->observations,
        ];
    }
}
