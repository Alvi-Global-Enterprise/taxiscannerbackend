<?php

declare(strict_types=1);

namespace App\Domain\Location\Services;

use App\Domain\Location\Contracts\GeocodingServiceInterface;
use App\Domain\Location\DTOs\Coordinates;
use App\Domain\Location\DTOs\Location;
use App\Domain\Location\Exceptions\GeocodingException;

class SimulatedGeocodingService implements GeocodingServiceInterface
{
    /**
     * Pre-mapped UK landmarks, airports, and city centres for accurate demonstration.
     * Can be extended or replaced by Google Maps / Mapbox / OpenStreetMap drivers.
     *
     * @var array<string, array{lat: float, lng: float, name: string, city: string, postcode: string}>
     */
    private array $knownPlaces = [
        'manchester airport' => [
            'lat' => 53.3588,
            'lng' => -2.2727,
            'name' => 'Manchester Airport (MAN), Ringway, Manchester',
            'city' => 'Manchester',
            'postcode' => 'M90 1QX',
        ],
        'manchester city centre' => [
            'lat' => 53.4808,
            'lng' => -2.2426,
            'name' => 'Manchester City Centre, Manchester, UK',
            'city' => 'Manchester',
            'postcode' => 'M1 1AD',
        ],
        'manchester piccadilly' => [
            'lat' => 53.4774,
            'lng' => -2.2312,
            'name' => 'Manchester Piccadilly Station, Manchester, UK',
            'city' => 'Manchester',
            'postcode' => 'M60 7RA',
        ],
        'stockport railway station' => [
            'lat' => 53.4074,
            'lng' => -2.1634,
            'name' => 'Stockport Railway Station, Grand Central Way, Stockport, SK3 9HZ, United Kingdom',
            'city' => 'Stockport',
            'postcode' => 'SK3 9HZ',
        ],
        'stockport station' => [
            'lat' => 53.4074,
            'lng' => -2.1634,
            'name' => 'Stockport Railway Station, Grand Central Way, Stockport, SK3 9HZ, United Kingdom',
            'city' => 'Stockport',
            'postcode' => 'SK3 9HZ',
        ],
        'old trafford' => [
            'lat' => 53.4631,
            'lng' => -2.2913,
            'name' => 'Old Trafford Stadium, Sir Matt Busby Way, Stretford, Manchester',
            'city' => 'Manchester',
            'postcode' => 'M16 0RA',
        ],
        'etihad stadium' => [
            'lat' => 53.4831,
            'lng' => -2.2004,
            'name' => 'Etihad Stadium, Ashton New Rd, Manchester',
            'city' => 'Manchester',
            'postcode' => 'M11 3FF',
        ],
        'salford quays' => [
            'lat' => 53.4722,
            'lng' => -2.2858,
            'name' => 'Salford Quays, MediaCityUK, Salford',
            'city' => 'Salford',
            'postcode' => 'M50 3AZ',
        ],
        'heathrow airport' => [
            'lat' => 51.4700,
            'lng' => -0.4543,
            'name' => 'Heathrow Airport (LHR), Longford, Hounslow',
            'city' => 'London',
            'postcode' => 'TW6 1AP',
        ],
        'central london' => [
            'lat' => 51.5074,
            'lng' => -0.1278,
            'name' => 'Trafalgar Square, Central London, UK',
            'city' => 'London',
            'postcode' => 'WC2N 5DN',
        ],
        'birmingham new street' => [
            'lat' => 52.4778,
            'lng' => -1.8986,
            'name' => 'Birmingham New Street Station, Birmingham, UK',
            'city' => 'Birmingham',
            'postcode' => 'B2 4QA',
        ],
        'leeds city centre' => [
            'lat' => 53.7997,
            'lng' => -1.5492,
            'name' => 'Leeds City Centre, Leeds, UK',
            'city' => 'Leeds',
            'postcode' => 'LS1 1UR',
        ],
    ];

    public function geocode(string $address, ?Coordinates $proximity = null, ?string $referenceCity = null): Location
    {
        $normalized = strtolower(trim($address));

        if (empty($normalized)) {
            throw new GeocodingException($address, 'Address query cannot be empty.');
        }

        // Direct or partial match in known places
        foreach ($this->knownPlaces as $key => $info) {
            if (str_contains($normalized, $key) || str_contains($key, $normalized)) {
                return new Location(
                    query: $address,
                    formattedAddress: $info['name'],
                    coordinates: new Coordinates($info['lat'], $info['lng']),
                    city: $info['city'],
                    postcode: $info['postcode'],
                    country: 'GB',
                    placeId: 'ts_known_'.md5($key),
                );
            }
        }

        // Deterministic pseudo-geocoding for arbitrary addresses in UK bounds
        // This ensures unmapped test addresses still generate valid coordinates
        // until an external Geocoding API key (Google/Mapbox) is provided.
        $hash = crc32($normalized);
        $latOffset = (($hash % 2000) - 1000) / 10000.0;
        $lngOffset = ((($hash >> 8) % 2000) - 1000) / 10000.0;

        $baseLat = 53.4808; // Manchester centre
        $baseLng = -2.2426;

        return new Location(
            query: $address,
            formattedAddress: ucwords(trim($address)).', UK',
            coordinates: new Coordinates(
                latitude: round($baseLat + $latOffset, 6),
                longitude: round($baseLng + $lngOffset, 6),
            ),
            city: 'Manchester',
            postcode: null,
            country: 'GB',
            placeId: 'ts_sim_'.substr(md5($normalized), 0, 12),
        );
    }
}
