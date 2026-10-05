<?php

declare(strict_types=1);

namespace App\Domain\Pricing\DTOs;

final readonly class PricingBreakdown
{
    public function __construct(
        public float $baseFare = 0.0,
        public float $distanceCharge = 0.0,
        public float $durationCharge = 0.0,
        public float $bookingFee = 0.0,
        public float $airportFee = 0.0,
        public float $surgeMultiplier = 1.0,
        public float $calibrationMultiplier = 1.0,
        public float $subtotal = 0.0,
        public float $total = 0.0,
        public array $extraItems = [],
    ) {}

    public function toArray(): array
    {
        return [
            'base_fare' => $this->baseFare,
            'distance_charge' => $this->distanceCharge,
            'duration_charge' => $this->durationCharge,
            'booking_fee' => $this->bookingFee,
            'airport_fee' => $this->airportFee,
            'surge_multiplier' => $this->surgeMultiplier,
            'calibration_multiplier' => $this->calibrationMultiplier,
            'subtotal' => $this->subtotal,
            'total' => $this->total,
            'extra_items' => $this->extraItems,
        ];
    }
}
