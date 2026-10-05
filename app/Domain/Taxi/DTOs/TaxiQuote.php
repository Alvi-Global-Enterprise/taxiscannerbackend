<?php

declare(strict_types=1);

namespace App\Domain\Taxi\DTOs;

use App\Domain\Taxi\Enums\QuoteType;
use App\Domain\Taxi\Enums\TaxiProvider;

final readonly class TaxiQuote
{
    public function __construct(
        public TaxiProvider|string $provider,
        public string $providerDisplayName,
        public ?PriceRange $priceRange,
        public string $currency = 'GBP',
        public ?int $estimatedPickupMinutes = null,
        public ?int $estimatedDurationMinutes = null,
        public ?float $distanceMiles = null,
        public QuoteType $quoteType = QuoteType::ESTIMATE,
        public bool $isAvailable = true,
        public ?string $bookingUrl = null,
        public array $metadata = [],
        public ?string $errorMessage = null,
    ) {}

    public function providerSlug(): string
    {
        return $this->provider instanceof TaxiProvider ? $this->provider->value : (string) $this->provider;
    }

    public static function unavailable(
        TaxiProvider|string $provider,
        string $displayName,
        string $errorMessage = 'Quote currently unavailable',
        string $currency = 'GBP',
        ?string $bookingUrl = null,
    ): self {
        return new self(
            provider: $provider,
            providerDisplayName: $displayName,
            priceRange: null,
            currency: $currency,
            estimatedPickupMinutes: null,
            estimatedDurationMinutes: null,
            distanceMiles: null,
            quoteType: QuoteType::ESTIMATE,
            isAvailable: false,
            bookingUrl: $bookingUrl,
            metadata: [],
            errorMessage: $errorMessage,
        );
    }

    public function toArray(): array
    {
        return [
            'provider' => $this->providerSlug(),
            'display_name' => $this->providerDisplayName,
            'min_price' => $this->priceRange?->min,
            'max_price' => $this->priceRange?->max,
            'is_fixed_price' => $this->priceRange?->isFixed ?? false,
            'currency' => $this->currency,
            'estimated_pickup_minutes' => $this->estimatedPickupMinutes,
            'estimated_duration_minutes' => $this->estimatedDurationMinutes,
            'distance_miles' => $this->distanceMiles,
            'quote_type' => $this->quoteType->value,
            'is_available' => $this->isAvailable,
            'booking_url' => $this->bookingUrl,
            'metadata' => $this->metadata,
            'error_message' => $this->errorMessage,
        ];
    }
}
