<?php

declare(strict_types=1);

namespace App\Domain\Location\DTOs;

final readonly class Coordinates
{
    public function __construct(
        public float $latitude,
        public float $longitude,
    ) {}

    public function toArray(): array
    {
        return [
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ];
    }

    /**
     * Calculate great-circle (Haversine) distance in miles to another coordinate pair.
     */
    public function distanceToInMiles(Coordinates $other): float
    {
        $earthRadiusMiles = 3958.8;

        $latDelta = deg2rad($other->latitude - $this->latitude);
        $lonDelta = deg2rad($other->longitude - $this->longitude);

        $a = sin($latDelta / 2) * sin($latDelta / 2) +
            cos(deg2rad($this->latitude)) * cos(deg2rad($other->latitude)) *
            sin($lonDelta / 2) * sin($lonDelta / 2);

        $a = min(1.0, max(0.0, $a));
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($earthRadiusMiles * $c, 2);
    }
}
