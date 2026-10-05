<?php

declare(strict_types=1);

namespace App\Domain\Pricing\DTOs;

final readonly class GeographicAdjustmentResult
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public string $zoneKey,
        public string $zoneName,
        public float $multiplier,
        public ?float $minimumFloor = null,
        public ?float $fixedFare = null,
        public float $confidence = 1.0,
        public int $priority = 50,
        public string $matchReason = '',
        public array $extra = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'zone_key' => $this->zoneKey,
            'zone_name' => $this->zoneName,
            'multiplier' => $this->multiplier,
            'minimum_floor' => $this->minimumFloor,
            'fixed_fare' => $this->fixedFare,
            'confidence' => $this->confidence,
            'priority' => $this->priority,
            'match_reason' => $this->matchReason,
            'extra' => $this->extra,
        ];
    }
}
