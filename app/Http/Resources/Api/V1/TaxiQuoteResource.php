<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Taxi\DTOs\TaxiQuote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read TaxiQuote $resource
 */
class TaxiQuoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var TaxiQuote $quote */
        $quote = $this->resource;

        return [
            'provider' => $quote->providerSlug(),
            'display_name' => $quote->providerDisplayName,
            'min_price' => $quote->priceRange?->min,
            'max_price' => $quote->priceRange?->max,
            'is_fixed_price' => $quote->priceRange?->isFixed ?? false,
            'currency' => $quote->currency,
            'estimated_pickup_minutes' => $quote->estimatedPickupMinutes,
            'estimated_duration_minutes' => $quote->estimatedDurationMinutes,
            'distance_miles' => $quote->distanceMiles,
            'quote_type' => $quote->quoteType->value,
            'is_available' => $quote->isAvailable,
            'booking_url' => $quote->bookingUrl,
            'metadata' => $quote->metadata,
            'error_message' => $quote->errorMessage,
        ];
    }
}
