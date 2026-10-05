<?php

declare(strict_types=1);

namespace App\Domain\Location\Services;

use App\Domain\Location\Contracts\RouteServiceInterface;
use App\Domain\Location\DTOs\Location;
use App\Domain\Location\DTOs\RouteInformation;
use App\Domain\Location\Exceptions\RouteCalculationException;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;
use Throwable;

class MapboxRouteService implements RouteServiceInterface
{
    public function __construct(
        private readonly ?string $apiKey,
        private readonly LoggerInterface $logger,
        private readonly int $timeoutSeconds = 5,
        private readonly ?RouteServiceInterface $fallbackDriver = null,
    ) {}

    public function calculateRoute(Location $origin, Location $destination): RouteInformation
    {
        $lat1 = $origin->coordinates->latitude;
        $lon1 = $origin->coordinates->longitude;
        $lat2 = $destination->coordinates->latitude;
        $lon2 = $destination->coordinates->longitude;

        // Check for identical coordinates
        if (abs($lat1 - $lat2) < 0.00001 && abs($lon1 - $lon2) < 0.00001) {
            throw new RouteCalculationException('Pickup and dropoff locations cannot be identical.');
        }

        // Validate coordinate ranges
        if ($lat1 < -90.0 || $lat1 > 90.0 || $lat2 < -90.0 || $lat2 > 90.0 ||
            $lon1 < -180.0 || $lon1 > 180.0 || $lon2 < -180.0 || $lon2 > 180.0) {
            throw new RouteCalculationException('Invalid geographical coordinates provided for route calculation.');
        }

        // If API key is missing, check if an explicit development fallback is permitted
        if (empty($this->apiKey)) {
            if ($this->fallbackDriver !== null) {
                $this->logger->warning('Mapbox API key not configured; using configured development fallback routing driver.');

                return $this->fallbackDriver->calculateRoute($origin, $destination);
            }

            $this->logger->error('Mapbox routing failed: TAXISCANNER_MAP_API_KEY is not configured.');
            throw new RouteCalculationException('Routing service is unavailable. Please check system configuration.');
        }

        // Mapbox Directions coordinates format: {longitude},{latitude};{longitude},{latitude}
        $coordinatesString = sprintf('%.6f,%.6f;%.6f,%.6f', $lon1, $lat1, $lon2, $lat2);

        $endpoint = sprintf(
            'https://api.mapbox.com/directions/v5/mapbox/driving/%s',
            $coordinatesString
        );

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->acceptJson()
                ->get($endpoint, [
                    'access_token' => $this->apiKey,
                    'geometries' => 'geojson',
                    'overview' => 'simplified',
                    'steps' => 'false',
                ]);
        } catch (Throwable $e) {
            $this->logger->error('Mapbox directions HTTP request exception', [
                'origin' => $origin->formattedAddress,
                'destination' => $destination->formattedAddress,
                'error' => $e->getMessage(),
            ]);

            if ($this->fallbackDriver !== null) {
                return $this->fallbackDriver->calculateRoute($origin, $destination);
            }

            throw new RouteCalculationException('Routing service temporarily unavailable. Please try again.');
        }

        if (! $response->successful()) {
            $this->logger->error('Mapbox directions returned error response', [
                'status' => $response->status(),
                'origin' => $origin->formattedAddress,
                'destination' => $destination->formattedAddress,
            ]);

            if ($this->fallbackDriver !== null) {
                return $this->fallbackDriver->calculateRoute($origin, $destination);
            }

            if ($response->status() === 429) {
                throw new RouteCalculationException('Routing service rate limit reached. Please try again shortly.');
            }

            throw new RouteCalculationException('Unable to calculate driving route between the specified locations.');
        }

        $payload = $response->json();
        $code = (string) ($payload['code'] ?? '');

        if ($code !== 'Ok' || empty($payload['routes']) || ! is_array($payload['routes'][0])) {
            throw new RouteCalculationException(sprintf(
                'No drivable road route found between "%s" and "%s".',
                $origin->formattedAddress,
                $destination->formattedAddress
            ));
        }

        $route = $payload['routes'][0];
        $distanceMeters = (int) round((float) ($route['distance'] ?? 0));
        $durationSeconds = (int) round((float) ($route['duration'] ?? 0));

        if ($distanceMeters <= 0) {
            throw new RouteCalculationException('Driving distance could not be determined.');
        }

        $polyline = null;
        if (isset($route['geometry']['coordinates']) && is_array($route['geometry']['coordinates'])) {
            $polyline = json_encode($route['geometry']['coordinates']);
        }

        $summary = null;
        if (! empty($route['legs'][0]['summary'])) {
            $summary = 'Via '.(string) $route['legs'][0]['summary'];
        }

        return RouteInformation::fromCalculatedValues(
            origin: $origin,
            destination: $destination,
            distanceMeters: $distanceMeters,
            durationSeconds: $durationSeconds,
            summary: $summary,
            polyline: $polyline,
            isEstimated: false, // Real road routing!
        );
    }
}
