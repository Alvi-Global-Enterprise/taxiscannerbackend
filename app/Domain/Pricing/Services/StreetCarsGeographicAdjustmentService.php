<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Location\DTOs\Coordinates;
use App\Domain\Pricing\DTOs\GeographicAdjustmentResult;
use App\Domain\Pricing\DTOs\PricingBreakdown;
use App\Domain\Pricing\DTOs\PricingCalculationInput;

class StreetCarsGeographicAdjustmentService
{
    /**
     * @param  array<string, mixed>|null  $customConfig
     */
    public function __construct(
        private readonly ?array $customConfig = null,
    ) {}

    /**
     * Retrieve the configuration array.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        if ($this->customConfig !== null) {
            return $this->customConfig;
        }

        return config('taxiscanner.streetcars.geographic_calibration')
            ?? config('taxiscanner.providers.streetcars.geographic_calibration')
            ?? [];
    }

    /**
     * Determine if geographic calibration is enabled in configuration.
     */
    public function isEnabled(): bool
    {
        return (bool) ($this->getConfig()['enabled'] ?? false);
    }

    /**
     * Minimum confidence score required to accept a zone match without guessing.
     */
    public function getConfidenceThreshold(): float
    {
        return (float) ($this->getConfig()['confidence_threshold'] ?? 0.60);
    }

    /**
     * Retrieve configured empirical calibration zones.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getZones(): array
    {
        return (array) ($this->getConfig()['zones'] ?? []);
    }

    /**
     * Check if a trip is an airport_to_suburb trip.
     */
    public function isAirportToSuburb(PricingCalculationInput $input, string $tripCategory): bool
    {
        return $tripCategory === 'airport_to_suburb';
    }

    /**
     * Check whether geographic adjustment is eligible to run on this trip.
     */
    public function isEligible(PricingCalculationInput $input, string $tripCategory): bool
    {
        return $this->isEnabled() && $this->isAirportToSuburb($input, $tripCategory);
    }

    /**
     * Resolve the empirical geographic adjustment for an eligible trip.
     */
    public function resolveAdjustment(PricingCalculationInput $input, string $tripCategory): ?GeographicAdjustmentResult
    {
        if (! $this->isEligible($input, $tripCategory)) {
            return null;
        }

        $destination = $input->route->destination;
        $address = $destination->formattedAddress ?? '';
        $query = $input->trip->dropoffQuery ?: ($destination->query ?? '');
        $coordinates = $destination->coordinates ?? null;
        $postcode = $destination->postcode ?? null;

        return $this->matchZone($address, $query, $coordinates, $postcode);
    }

    /**
     * Match a destination against configured empirical zones using keywords,
     * coordinates, and postcode prefix with confidence and deterministic precedence.
     */
    public function matchZone(
        string $address = '',
        string $query = '',
        ?Coordinates $coordinates = null,
        ?string $postcode = null,
    ): ?GeographicAdjustmentResult {
        $threshold = $this->getConfidenceThreshold();
        $zones = $this->getZones();

        if (empty($zones)) {
            return null;
        }

        $combinedText = strtolower(trim($query.' '.$address.' '.($postcode ?? '')));
        $candidates = [];

        foreach ($zones as $zoneKey => $zone) {
            // Negative Keywords Guard (Zone Exclusion)
            $negativeKeywords = (array) ($zone['negative_keywords'] ?? []);
            foreach ($negativeKeywords as $nkw) {
                $nkw = strtolower(trim((string) $nkw));
                if ($nkw !== '' && preg_match('/\b'.preg_quote($nkw, '/').'\b/i', $combinedText) === 1) {
                    continue 2;
                }
            }

            $keywords = (array) ($zone['keywords'] ?? []);

            // Special Guard: "Wilmslow Road" must NOT by itself qualify a destination as Wilmslow
            // If the address contains "Wilmslow Road", require SK9 postcode/context before applying Wilmslow zone
            if ($zoneKey === 'handforth_wilmslow') {
                $hasWilmslowRoad = preg_match('/\bwilmslow\s+road\b/i', $combinedText) === 1;
                $hasSk9 = preg_match('/\bsk9\b/i', $combinedText) === 1;
                if ($hasWilmslowRoad && ! $hasSk9) {
                    $keywords = array_values(array_filter(
                        $keywords,
                        fn ($k) => strtolower(trim((string) $k)) !== 'wilmslow'
                    ));
                }
            }

            $keywordScore = 0.0;
            $matchedKeyword = null;

            foreach ($keywords as $kw) {
                $kw = strtolower(trim((string) $kw));
                if ($kw === '') {
                    continue;
                }

                if (preg_match('/\b'.preg_quote($kw, '/').'\b/i', $combinedText) === 1) {
                    $keywordScore = 1.0;
                    $matchedKeyword = $kw;
                    break;
                }

                if (str_contains($combinedText, $kw)) {
                    $keywordScore = max($keywordScore, 0.80);
                    $matchedKeyword = $kw;
                }
            }

            // Postcode Prefix Match
            $postcodePrefixes = (array) ($zone['postcode_prefixes'] ?? []);
            $postcodeScore = 0.0;
            foreach ($postcodePrefixes as $prefix) {
                $prefix = strtoupper(trim((string) $prefix));
                if ($prefix === '') {
                    continue;
                }

                if (preg_match('/\b'.preg_quote($prefix, '/').'\b/i', $combinedText) === 1) {
                    $postcodeScore = 1.0;
                    break;
                }
            }

            // Coordinate Proximity Match
            $coordScore = 0.0;
            $distanceToCenter = null;
            $centerData = $zone['center'] ?? null;
            $radiusMiles = (float) ($zone['radius_miles'] ?? 5.0);

            if ($coordinates instanceof Coordinates && is_array($centerData) && isset($centerData['latitude'], $centerData['longitude'])) {
                $centerCoord = new Coordinates((float) $centerData['latitude'], (float) $centerData['longitude']);
                $dist = $coordinates->distanceToInMiles($centerCoord);
                $distanceToCenter = $dist;

                if ($dist <= $radiusMiles) {
                    $coordScore = max(0.0, 1.0 - (0.35 * ($dist / max(0.1, $radiusMiles))));
                }
            }

            // Calculate Combined Confidence
            $confidence = 0.0;
            $matchReason = '';

            if ($keywordScore > 0.0 && $coordScore > 0.0) {
                $confidence = round(min(1.0, (0.50 * $keywordScore) + (0.40 * $coordScore) + ($postcodeScore > 0 ? 0.10 : 0.05)), 2);
                $matchReason = sprintf('Keyword ("%s") and coordinate match (%.2f mi from center)', (string) $matchedKeyword, (float) $distanceToCenter);
            } elseif ($keywordScore > 0.0) {
                $confidence = round($keywordScore * ($postcodeScore > 0 ? 0.95 : 0.85), 2);
                $matchReason = sprintf('Keyword match ("%s")', (string) $matchedKeyword);
            } elseif ($coordScore > 0.0 && $postcodeScore > 0.0) {
                $confidence = round(min(0.90, (0.40 * $coordScore) + 0.45), 2);
                $matchReason = sprintf('Postcode prefix and coordinate match (%.2f mi from center)', (float) $distanceToCenter);
            } elseif ($coordScore > 0.0) {
                // When neither keyword nor postcode matches, coordinate-only confidence has a maximum of 0.55
                // Since the confidence threshold is 0.60, coordinate-only matches safely fall back
                $confidence = round(min(0.55, $coordScore * 0.55), 2);
                $matchReason = sprintf('Coordinate-only proximity (%.2f mi from center - below threshold)', (float) $distanceToCenter);
            } elseif ($postcodeScore > 0.0) {
                $confidence = 0.65;
                $matchReason = 'Postcode prefix match';
            }

            // Reject matches below confidence threshold
            if ($confidence < $threshold) {
                continue;
            }

            $candidates[] = [
                'zone_key' => (string) $zoneKey,
                'zone' => $zone,
                'priority' => (int) ($zone['priority'] ?? 50),
                'confidence' => $confidence,
                'distance' => $distanceToCenter ?? 999.0,
                'match_reason' => $matchReason,
            ];
        }

        if (empty($candidates)) {
            return null;
        }

        // Deterministic sorting: 1. Priority DESC, 2. Confidence DESC, 3. Distance ASC, 4. ZoneKey ASC
        usort($candidates, function (array $a, array $b): int {
            if ($a['priority'] !== $b['priority']) {
                return $b['priority'] <=> $a['priority'];
            }
            if ($a['confidence'] !== $b['confidence']) {
                return $b['confidence'] <=> $a['confidence'];
            }
            if ($a['distance'] !== $b['distance']) {
                return $a['distance'] <=> $b['distance'];
            }

            return strcmp($a['zone_key'], $b['zone_key']);
        });

        $winner = $candidates[0];
        $winZone = $winner['zone'];

        return new GeographicAdjustmentResult(
            zoneKey: $winner['zone_key'],
            zoneName: (string) ($winZone['name'] ?? $winner['zone_key']),
            multiplier: (float) ($winZone['multiplier'] ?? 1.0),
            minimumFloor: isset($winZone['minimum_floor']) && $winZone['minimum_floor'] !== null ? (float) $winZone['minimum_floor'] : null,
            fixedFare: isset($winZone['fixed_fare']) && $winZone['fixed_fare'] !== null ? (float) $winZone['fixed_fare'] : null,
            confidence: $winner['confidence'],
            priority: $winner['priority'],
            matchReason: $winner['match_reason'],
            extra: [
                'distance_to_center' => $winner['distance'] < 900 ? $winner['distance'] : null,
            ],
        );
    }

    /**
     * Apply geographic adjustment to a pricing breakdown if eligible.
     */
    public function applyAdjustment(
        PricingBreakdown $breakdown,
        PricingCalculationInput $input,
        string $tripCategory,
    ): PricingBreakdown {
        if (! $this->isEligible($input, $tripCategory)) {
            return $breakdown;
        }

        $adjustment = $this->resolveAdjustment($input, $tripCategory);
        if ($adjustment === null) {
            return $breakdown;
        }

        $subtotal = $breakdown->subtotal;
        $multiplier = $adjustment->multiplier;
        $adjustedTotal = round($subtotal * $multiplier, 2);

        $floorApplied = false;
        if ($adjustment->minimumFloor !== null && $adjustedTotal < $adjustment->minimumFloor) {
            $adjustedTotal = $adjustment->minimumFloor;
            $floorApplied = true;
        }

        if ($adjustment->fixedFare !== null) {
            $adjustedTotal = $adjustment->fixedFare;
        }

        $configMinFare = (float) ($input->config?->minimum_fare ?? 0.0);
        $finalTotal = max($adjustedTotal, $configMinFare);

        $extraItems = array_merge($breakdown->extraItems, [
            'geographic_calibration_applied' => true,
            'geographic_zone' => $adjustment->zoneKey,
            'geographic_zone_name' => $adjustment->zoneName,
            'geographic_confidence' => $adjustment->confidence,
            'geographic_precedence' => $adjustment->priority,
            'geographic_match_reason' => $adjustment->matchReason,
            'geographic_multiplier' => $multiplier,
            'geographic_floor_applied' => $floorApplied,
        ]);

        return new PricingBreakdown(
            baseFare: $breakdown->baseFare,
            distanceCharge: $breakdown->distanceCharge,
            durationCharge: $breakdown->durationCharge,
            bookingFee: $breakdown->bookingFee,
            airportFee: $breakdown->airportFee,
            surgeMultiplier: $breakdown->surgeMultiplier,
            calibrationMultiplier: $multiplier,
            subtotal: $subtotal,
            total: $finalTotal,
            extraItems: $extraItems,
        );
    }
}
