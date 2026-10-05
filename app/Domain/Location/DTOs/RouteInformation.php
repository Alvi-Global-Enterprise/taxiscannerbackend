<?php

declare(strict_types=1);

namespace App\Domain\Location\DTOs;

final readonly class RouteInformation
{
    public function __construct(
        public Location $origin,
        public Location $destination,
        public int $distanceMeters,
        public float $distanceMiles,
        public int $durationSeconds,
        public int $durationMinutes,
        public ?string $summary = null,
        public ?string $polyline = null,
        public bool $isEstimated = false,
    ) {}

    public static function fromCalculatedValues(
        Location $origin,
        Location $destination,
        int $distanceMeters,
        int $durationSeconds,
        ?string $summary = null,
        ?string $polyline = null,
        bool $isEstimated = false,
    ): self {
        $distanceMiles = round($distanceMeters * 0.000621371, 2);
        $durationMinutes = $durationSeconds > 0 ? (int) max(1, round($durationSeconds / 60)) : 0;

        return new self(
            origin: $origin,
            destination: $destination,
            distanceMeters: $distanceMeters,
            distanceMiles: $distanceMiles,
            durationSeconds: $durationSeconds,
            durationMinutes: $durationMinutes,
            summary: $summary,
            polyline: $polyline,
            isEstimated: $isEstimated,
        );
    }

    public function toArray(): array
    {
        return [
            'distance_meters' => $this->distanceMeters,
            'distance_miles' => $this->distanceMiles,
            'duration_seconds' => $this->durationSeconds,
            'duration_minutes' => $this->durationMinutes,
            'summary' => $this->summary,
            'polyline' => $this->polyline,
            'is_estimated' => $this->isEstimated,
        ];
    }
}
