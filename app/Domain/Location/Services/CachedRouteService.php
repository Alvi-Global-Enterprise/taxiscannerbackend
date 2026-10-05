<?php

declare(strict_types=1);

namespace App\Domain\Location\Services;

use App\Domain\Location\Contracts\RouteServiceInterface;
use App\Domain\Location\DTOs\Location;
use App\Domain\Location\DTOs\RouteInformation;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Psr\Log\LoggerInterface;

class CachedRouteService implements RouteServiceInterface
{
    public function __construct(
        private readonly RouteServiceInterface $inner,
        private readonly CacheRepository $cache,
        private readonly LoggerInterface $logger,
        private readonly int $ttl = 604800, // 7 days
        private readonly string $prefix = 'taxiscanner:route:',
    ) {}

    public function calculateRoute(Location $origin, Location $destination): RouteInformation
    {
        $cacheKey = $this->prefix.$this->generateRouteHash($origin, $destination);

        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            $this->logger->debug('Route lookup cache hit', [
                'cache_key' => $cacheKey,
                'origin' => $origin->formattedAddress,
                'destination' => $destination->formattedAddress,
            ]);

            return new RouteInformation(
                origin: $origin,
                destination: $destination,
                distanceMeters: (int) $cached['distance_meters'],
                distanceMiles: (float) $cached['distance_miles'],
                durationSeconds: (int) $cached['duration_seconds'],
                durationMinutes: (int) $cached['duration_minutes'],
                summary: $cached['summary'] ?? null,
                polyline: $cached['polyline'] ?? null,
                isEstimated: (bool) ($cached['is_estimated'] ?? true),
            );
        }

        $this->logger->debug('Route lookup cache miss; calculating route', [
            'origin' => $origin->formattedAddress,
            'destination' => $destination->formattedAddress,
        ]);

        $route = $this->inner->calculateRoute($origin, $destination);

        $this->cache->put($cacheKey, $route->toArray(), $this->ttl);

        return $route;
    }

    private function generateRouteHash(Location $origin, Location $destination): string
    {
        // Round coordinates to ~11 meters precision for robust cache clustering
        $origKey = sprintf('%.4f,%.4f', $origin->coordinates->latitude, $origin->coordinates->longitude);
        $destKey = sprintf('%.4f,%.4f', $destination->coordinates->latitude, $destination->coordinates->longitude);

        return md5($origKey.'->'.$destKey);
    }
}
