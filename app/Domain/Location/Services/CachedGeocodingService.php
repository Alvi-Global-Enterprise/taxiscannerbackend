<?php

declare(strict_types=1);

namespace App\Domain\Location\Services;

use App\Domain\Location\Contracts\GeocodingServiceInterface;
use App\Domain\Location\DTOs\Coordinates;
use App\Domain\Location\DTOs\Location;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Psr\Log\LoggerInterface;

class CachedGeocodingService implements GeocodingServiceInterface
{
    public function __construct(
        private readonly GeocodingServiceInterface $inner,
        private readonly CacheRepository $cache,
        private readonly LoggerInterface $logger,
        private readonly int $ttl = 1209600, // 14 days
        private readonly string $prefix = 'taxiscanner:geo:',
    ) {}

    public function geocode(string $address, ?Coordinates $proximity = null, ?string $referenceCity = null): Location
    {
        $normalized = strtolower(trim($address));
        $proximitySuffix = $proximity ? sprintf(':prox:%.4f,%.4f', $proximity->latitude, $proximity->longitude) : '';
        $citySuffix = $referenceCity ? ':city:'.strtolower(trim($referenceCity)) : '';
        $cacheKey = $this->prefix.md5($normalized.$proximitySuffix.$citySuffix);

        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            $this->logger->debug('Geocoding cache hit', [
                'address' => $address,
                'cache_key' => $cacheKey,
            ]);

            return new Location(
                query: $cached['query'],
                formattedAddress: $cached['formatted_address'],
                coordinates: new Coordinates(
                    latitude: (float) $cached['coordinates']['latitude'],
                    longitude: (float) $cached['coordinates']['longitude'],
                ),
                city: $cached['city'] ?? null,
                postcode: $cached['postcode'] ?? null,
                country: $cached['country'] ?? 'GB',
                placeId: $cached['place_id'] ?? null,
            );
        }

        $this->logger->debug('Geocoding cache miss; resolving via provider', [
            'address' => $address,
        ]);

        $location = $this->inner->geocode($address, $proximity, $referenceCity);

        $this->cache->put($cacheKey, $location->toArray(), $this->ttl);

        return $location;
    }
}
