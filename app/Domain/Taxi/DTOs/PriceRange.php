<?php

declare(strict_types=1);

namespace App\Domain\Taxi\DTOs;

final readonly class PriceRange
{
    public function __construct(
        public float $min,
        public float $max,
        public string $currency = 'GBP',
        public bool $isFixed = false,
    ) {}

    public static function fixed(float $amount, string $currency = 'GBP'): self
    {
        return new self(
            min: $amount,
            max: $amount,
            currency: $currency,
            isFixed: true,
        );
    }

    public static function range(float $min, float $max, string $currency = 'GBP'): self
    {
        return new self(
            min: min($min, $max),
            max: max($min, $max),
            currency: $currency,
            isFixed: $min === $max,
        );
    }

    public function average(): float
    {
        return round(($this->min + $this->max) / 2, 2);
    }

    public function toArray(): array
    {
        return [
            'min' => $this->min,
            'max' => $this->max,
            'currency' => $this->currency,
            'is_fixed' => $this->isFixed,
        ];
    }
}
