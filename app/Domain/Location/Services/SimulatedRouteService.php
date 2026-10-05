<?php

declare(strict_types=1);

namespace App\Domain\Location\Services;

use App\Domain\Location\Contracts\RouteServiceInterface;
use App\Domain\Location\DTOs\Location;
use App\Domain\Location\DTOs\RouteInformation;
use App\Domain\Location\Exceptions\RouteCalculationException;

class SimulatedRouteService implements RouteServiceInterface
{
    /**
     * Calculate route between two locations using spherical trigonometry
     * adjusted with standard UK road winding factor.
     */
    public function calculateRoute(Location $origin, Location $destination): RouteInformation
    {
        $lat1 = $origin->coordinates->latitude;
        $lon1 = $origin->coordinates->longitude;
        $lat2 = $destination->coordinates->latitude;
        $lon2 = $destination->coordinates->longitude;

        if ($lat1 === $lat2 && $lon1 === $lon2) {
            throw new RouteCalculationException('Pickup and dropoff locations cannot be identical.');
        }

        // Haversine straight-line distance in meters
        $earthRadius = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $straightDistance = $earthRadius * $c;

        // Road network detour factor (approx 1.28x straight line for UK roads)
        $roadFactor = 1.28;
        $distanceMeters = (int) max(500, round($straightDistance * $roadFactor));

        // Average driving speed in UK metropolitan areas: ~26 mph (11.6 m/s)
        $averageSpeedMps = 11.6;
        $baseDurationSeconds = (int) max(180, round($distanceMeters / $averageSpeedMps));

        // Add 2-3 minutes traffic/junction buffer
        $durationSeconds = $baseDurationSeconds + 120;

        return RouteInformation::fromCalculatedValues(
            origin: $origin,
            destination: $destination,
            distanceMeters: $distanceMeters,
            durationSeconds: $durationSeconds,
            summary: sprintf('Via main UK route between %s and %s', $origin->city ?? 'origin', $destination->city ?? 'destination'),
            polyline: null,
            isEstimated: true,
        );
    }
}
