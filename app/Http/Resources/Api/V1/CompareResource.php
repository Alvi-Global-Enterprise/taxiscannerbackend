<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Taxi\DTOs\TaxiComparisonResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read TaxiComparisonResult $resource
 */
class CompareResource extends JsonResource
{
    /**
     * Disable default top-level data wrapping so we control the exact response envelope.
     */
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var TaxiComparisonResult $result */
        $result = $this->resource;

        return [
            'success' => true,
            'data' => [
                'pickup' => [
                    'query' => $result->pickup->query,
                    'formatted_address' => $result->pickup->formattedAddress,
                    'coordinates' => [
                        'latitude' => $result->pickup->coordinates->latitude,
                        'longitude' => $result->pickup->coordinates->longitude,
                    ],
                    'city' => $result->pickup->city,
                    'postcode' => $result->pickup->postcode,
                    'country' => $result->pickup->country,
                ],
                'dropoff' => [
                    'query' => $result->dropoff->query,
                    'formatted_address' => $result->dropoff->formattedAddress,
                    'coordinates' => [
                        'latitude' => $result->dropoff->coordinates->latitude,
                        'longitude' => $result->dropoff->coordinates->longitude,
                    ],
                    'city' => $result->dropoff->city,
                    'postcode' => $result->dropoff->postcode,
                    'country' => $result->dropoff->country,
                ],
                'route' => [
                    'distance_miles' => $result->route->distanceMiles,
                    'duration_minutes' => $result->route->durationMinutes,
                    'distance_meters' => $result->route->distanceMeters,
                    'duration_seconds' => $result->route->durationSeconds,
                    'summary' => $result->route->summary,
                    'is_estimated' => $result->route->isEstimated,
                ],
                'quotes' => TaxiQuoteResource::collection($result->quotes),
            ],
            'meta' => [
                'quote_type' => 'estimate',
                'disclaimer' => 'All quotes are estimated indicative fares. Live provider prices may vary depending on real-time availability and surge conditions.',
                'execution_time_ms' => $result->executionTimeMs,
            ],
        ];
    }
}
