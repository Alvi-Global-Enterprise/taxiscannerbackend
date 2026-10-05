<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Strategies;

use App\Domain\Pricing\Contracts\PricingStrategyInterface;
use App\Domain\Pricing\DTOs\PricingBreakdown;
use App\Domain\Pricing\DTOs\PricingCalculationInput;
use App\Domain\Pricing\Services\TripCategoryClassifier;
use App\Domain\Taxi\DTOs\PriceRange;
use App\Domain\Taxi\Enums\TaxiProvider;

abstract class AbstractPricingStrategy implements PricingStrategyInterface
{
    abstract public function supports(TaxiProvider $provider): bool;

    /**
     * Calculate price range based on database-driven pricing configuration.
     * Formula:
     *   distance_charge = distance_miles * per_mile_rate
     *   time_charge     = duration_minutes * per_minute_rate
     *   subtotal        = base_fare + distance_charge + time_charge + booking_fee + applicable_airport_fee
     *   adjusted_total  = subtotal * dynamic_multiplier * calibration_multiplier
     *   final_estimate  = max(adjusted_total, minimum_fare)
     */
    public function calculate(PricingCalculationInput $input): PriceRange
    {
        $breakdown = $this->calculateBreakdown($input);
        $currency = $this->resolveCurrency($input);

        $config = $input->config;
        $configData = $config?->config_data ?? [];

        $lowMultiplier = (float) ($configData['estimate_low_multiplier'] ?? 0.95);
        $highMultiplier = (float) ($configData['estimate_high_multiplier'] ?? 1.05);

        $minPrice = round($breakdown->total * $lowMultiplier, 2);
        $maxPrice = round($breakdown->total * $highMultiplier, 2);

        // Ensure minimum fare threshold applies to low range too if specified
        $minFare = $config?->minimum_fare ?? 0.0;
        if ($minFare > 0.0) {
            $minPrice = max($minPrice, $minFare);
            $maxPrice = max($maxPrice, $minFare);
        }

        return PriceRange::range($minPrice, $maxPrice, $currency);
    }

    public function calculateBreakdown(PricingCalculationInput $input): PricingBreakdown
    {
        $config = $input->config;

        $baseFare = (float) ($config?->base_fare ?? 0.0);
        $perMileRate = (float) ($config?->per_mile_rate ?? 0.0);
        $perMinuteRate = (float) ($config?->per_minute_rate ?? 0.0);
        $bookingFee = (float) ($config?->booking_fee ?? 0.0);
        $minimumFare = (float) ($config?->minimum_fare ?? 0.0);
        $dynamicMultiplier = (float) ($config?->dynamic_multiplier ?? 1.0);
        $calibrationMultiplier = (float) ($config?->calibration_multiplier ?? 1.0);
        $configData = $config?->config_data ?? [];
        $appliedCategory = null;

        $tripCategory = $this->resolveTripCategory($input);

        // Check if category-specific calibration multipliers are configured
        $categoryMultipliers = $configData['category_calibration_multipliers'] ?? [];
        if (! empty($categoryMultipliers) && is_array($categoryMultipliers)) {
            if (isset($categoryMultipliers[$tripCategory])) {
                $calibrationMultiplier = (float) $categoryMultipliers[$tripCategory];
                $appliedCategory = $tripCategory;
            } elseif ($tripCategory === 'inter_suburb' || $tripCategory === 'city_long') {
                $calibrationMultiplier = 1.0000;
                $appliedCategory = $tripCategory;
            }
        }

        if ($dynamicMultiplier <= 0.0) {
            $dynamicMultiplier = 1.0;
        }

        if ($calibrationMultiplier <= 0.0) {
            $calibrationMultiplier = 1.0;
        }

        // Distance & Time Charges
        $distanceMiles = max(0.0, (float) $input->route->distanceMiles);
        $durationMinutes = max(0, (int) $input->route->durationMinutes);

        $distanceCharge = round($distanceMiles * $perMileRate, 2);
        $durationCharge = round($durationMinutes * $perMinuteRate, 2);

        // Airport Fee Detection
        $airportFee = 0.0;
        $configuredAirportFee = (float) ($config?->airport_fee ?? 0.0);
        if ($configuredAirportFee > 0.0 && $this->isAirportTrip($input)) {
            $airportFee = $configuredAirportFee;
        }

        // Subtotal before multiplier
        $subtotal = round($baseFare + $distanceCharge + $durationCharge + $bookingFee + $airportFee, 2);

        // Adjusted Total with Dynamic Multiplier and Calibration Multiplier
        $adjustedTotal = round($subtotal * $dynamicMultiplier * $calibrationMultiplier, 2);

        // Minimum fare enforcement
        $finalTotal = max($adjustedTotal, $minimumFare);

        return new PricingBreakdown(
            baseFare: $baseFare,
            distanceCharge: $distanceCharge,
            durationCharge: $durationCharge,
            bookingFee: $bookingFee,
            airportFee: $airportFee,
            surgeMultiplier: $dynamicMultiplier,
            calibrationMultiplier: $calibrationMultiplier,
            subtotal: $subtotal,
            total: $finalTotal,
            extraItems: [
                'minimum_fare_applied' => $finalTotal > $adjustedTotal,
                'airport_detected' => $airportFee > 0.0,
                'calibration_applied' => $calibrationMultiplier !== 1.0,
                'calibration_category' => $appliedCategory,
                'trip_category' => $tripCategory,
            ],
        );
    }

    /**
     * Extensible airport trip detection based on Location and TripRequest queries.
     */
    protected function isAirportTrip(PricingCalculationInput $input): bool
    {
        $queries = [
            $input->trip->pickupQuery,
            $input->trip->dropoffQuery,
            $input->route->origin->query,
            $input->route->origin->formattedAddress,
            $input->route->destination->query,
            $input->route->destination->formattedAddress,
        ];

        // Standard UK airport keywords and airport IATA codes (Manchester, Heathrow, Gatwick, etc.)
        $pattern = '/\b(airport|aerodrome|terminal)\b|\b(MAN|LHR|LGW|STN|LTN|BHX|EDI|GLA|BFS|NCL|LPL|EMA|BRS)\b/i';

        foreach ($queries as $text) {
            if ($text && preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    protected function resolveCurrency(PricingCalculationInput $input): string
    {
        return $input->config?->currency ?? 'GBP';
    }

    /**
     * Resolve trip category from PricingCalculationInput for category-specific pricing calibration.
     */
    public function resolveTripCategory(PricingCalculationInput $input): string
    {
        $classifier = new TripCategoryClassifier;

        return $classifier->classifyFromInput($input);
    }
}
