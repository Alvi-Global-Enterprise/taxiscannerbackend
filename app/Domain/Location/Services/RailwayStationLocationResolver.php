<?php

declare(strict_types=1);

namespace App\Domain\Location\Services;

use App\Domain\Location\DTOs\Coordinates;
use App\Domain\Location\DTOs\Location;

class RailwayStationLocationResolver
{
    /**
     * Known major UK and Greater Manchester railway stations and transport hubs.
     * Clean and extensible: additional stations can also be configured via config/taxiscanner.php.
     *
     * @var array<string, array{name: string, crs?: string, formatted_address: string, lat: float, lon: float, city: string, postcode: string, aliases: list<string>}>
     */
    private array $stations;

    /**
     * @param  array<string, array{name: string, crs?: string, formatted_address: string, lat: float, lon: float, city: string, postcode: string, aliases: list<string>}>|null  $customStations
     */
    public function __construct(?array $customStations = null)
    {
        $defaults = [
            'spt' => [
                'name' => 'Stockport Railway Station',
                'crs' => 'SPT',
                'formatted_address' => 'Stockport Railway Station, Grand Central Way, Stockport, SK3 9HZ, United Kingdom',
                'lat' => 53.4074,
                'lon' => -2.1634,
                'city' => 'Stockport',
                'postcode' => 'SK3 9HZ',
                'aliases' => [
                    'stockport railway station',
                    'stockport train station',
                    'stockport rail station',
                    'stockport station',
                    'stockport rail',
                ],
            ],
            'man' => [
                'name' => 'Manchester Piccadilly Station',
                'crs' => 'MAN',
                'formatted_address' => 'Manchester Piccadilly Station, Piccadilly Station Approach, Manchester, M60 7RA, United Kingdom',
                'lat' => 53.4774,
                'lon' => -2.2312,
                'city' => 'Manchester',
                'postcode' => 'M60 7RA',
                'aliases' => [
                    'manchester piccadilly station',
                    'manchester piccadilly',
                    'piccadilly station',
                    'piccadilly railway station',
                    'piccadilly train station',
                ],
            ],
            'mcv' => [
                'name' => 'Manchester Victoria Station',
                'crs' => 'MCV',
                'formatted_address' => 'Manchester Victoria Station, Station Approach, Manchester, M3 1WY, United Kingdom',
                'lat' => 53.4875,
                'lon' => -2.2422,
                'city' => 'Manchester',
                'postcode' => 'M3 1WY',
                'aliases' => [
                    'manchester victoria station',
                    'manchester victoria',
                    'victoria station manchester',
                    'victoria railway station manchester',
                    'victoria train station manchester',
                ],
            ],
            'mco' => [
                'name' => 'Manchester Oxford Road Station',
                'crs' => 'MCO',
                'formatted_address' => 'Manchester Oxford Road Station, Oxford Road, Manchester, M1 6FU, United Kingdom',
                'lat' => 53.4740,
                'lon' => -2.2415,
                'city' => 'Manchester',
                'postcode' => 'M1 6FU',
                'aliases' => [
                    'manchester oxford road station',
                    'manchester oxford road',
                    'oxford road station',
                    'oxford road railway station',
                    'oxford road train station',
                ],
            ],
            'sfd' => [
                'name' => 'Salford Central Station',
                'crs' => 'SFD',
                'formatted_address' => 'Salford Central Station, New Bailey Street, Salford, M3 5ET, United Kingdom',
                'lat' => 53.4828,
                'lon' => -2.2547,
                'city' => 'Salford',
                'postcode' => 'M3 5ET',
                'aliases' => [
                    'salford central station',
                    'salford central railway station',
                    'salford central train station',
                    'salford central',
                ],
            ],
            'sld' => [
                'name' => 'Salford Crescent Station',
                'crs' => 'SLD',
                'formatted_address' => 'Salford Crescent Station, University Road, Salford, M5 4BR, United Kingdom',
                'lat' => 53.4855,
                'lon' => -2.2743,
                'city' => 'Salford',
                'postcode' => 'M5 4BR',
                'aliases' => [
                    'salford crescent station',
                    'salford crescent railway station',
                    'salford crescent train station',
                    'salford crescent',
                ],
            ],
            'bon' => [
                'name' => 'Bolton Railway Station',
                'crs' => 'BON',
                'formatted_address' => 'Bolton Railway Station, Trinity Street, Bolton, BL2 1BE, United Kingdom',
                'lat' => 53.5762,
                'lon' => -2.4276,
                'city' => 'Bolton',
                'postcode' => 'BL2 1BE',
                'aliases' => [
                    'bolton railway station',
                    'bolton train station',
                    'bolton rail station',
                    'bolton station',
                ],
            ],
            'wgn' => [
                'name' => 'Wigan North Western Station',
                'crs' => 'WGN',
                'formatted_address' => 'Wigan North Western Station, Wallgate, Wigan, WN1 1BJ, United Kingdom',
                'lat' => 53.5434,
                'lon' => -2.6327,
                'city' => 'Wigan',
                'postcode' => 'WN1 1BJ',
                'aliases' => [
                    'wigan north western station',
                    'wigan north western',
                    'wigan railway station',
                    'wigan train station',
                ],
            ],
            'alt' => [
                'name' => 'Altrincham Interchange',
                'crs' => 'ALT',
                'formatted_address' => 'Altrincham Interchange, Stamford New Road, Altrincham, WA14 1EN, United Kingdom',
                'lat' => 53.3871,
                'lon' => -2.3486,
                'city' => 'Altrincham',
                'postcode' => 'WA14 1EN',
                'aliases' => [
                    'altrincham interchange',
                    'altrincham station',
                    'altrincham railway station',
                    'altrincham train station',
                ],
            ],
            'eus' => [
                'name' => 'London Euston Station',
                'crs' => 'EUS',
                'formatted_address' => 'London Euston Station, Euston Road, London, NW1 2RT, United Kingdom',
                'lat' => 51.5284,
                'lon' => -0.1332,
                'city' => 'London',
                'postcode' => 'NW1 2RT',
                'aliases' => [
                    'london euston station',
                    'euston station',
                    'london euston',
                    'euston railway station',
                ],
            ],
            'bhm' => [
                'name' => 'Birmingham New Street Station',
                'crs' => 'BHM',
                'formatted_address' => 'Birmingham New Street Station, Station Street, Birmingham, B2 4QA, United Kingdom',
                'lat' => 52.4778,
                'lon' => -1.8986,
                'city' => 'Birmingham',
                'postcode' => 'B2 4QA',
                'aliases' => [
                    'birmingham new street station',
                    'birmingham new street',
                    'new street station',
                ],
            ],
            'lds' => [
                'name' => 'Leeds Railway Station',
                'crs' => 'LDS',
                'formatted_address' => 'Leeds Railway Station, New Station Street, Leeds, LS1 4DY, United Kingdom',
                'lat' => 53.7949,
                'lon' => -1.5476,
                'city' => 'Leeds',
                'postcode' => 'LS1 4DY',
                'aliases' => [
                    'leeds railway station',
                    'leeds train station',
                    'leeds station',
                ],
            ],
        ];

        $configured = $customStations ?? config('taxiscanner.stations', []);
        $this->stations = array_merge($defaults, $configured);
    }

    /**
     * Determine if a query indicates an intended railway/train station search.
     */
    public function isStationQuery(string $query): bool
    {
        $lower = strtolower(trim($query));

        if (empty($lower)) {
            return false;
        }

        // 1. Exclude non-railway stations (police, fire, bus, coach, petrol, service, power station, substation)
        if (preg_match('/\b(police|fire|bus|coach|petrol|service|power|substation)\s+station\b/i', $lower)) {
            return false;
        }

        // 2. Exclude ordinary street addresses like "10 Station Road", "Unit 4 Station Street"
        // where query begins with building/unit numbers before station road/street/close/way
        // and does NOT contain explicit railway or train keywords.
        if (preg_match('/^\s*\d+[\w\s\-\/]*\bstation\s+(road|street|st|rd|ave|avenue|lane|close|cl|cres|crescent|way|view|court)\b/i', $lower)
            && ! preg_match('/\b(railway|train|rail\s+station)\b/i', $lower)) {
            return false;
        }

        // 3. Direct keywords for railway / train station
        if (preg_match('/\b(railway\s+station|train\s+station|rail\s+station|railway\s+stn|train\s+stn)\b/i', $lower)) {
            return true;
        }

        // 4. Check known stations registry (names, crs codes, and explicit aliases)
        $clean = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $lower);
        $clean = preg_replace('/\s+/', ' ', trim((string) $clean));

        foreach ($this->stations as $entry) {
            if (isset($entry['crs']) && $clean === strtolower($entry['crs'])) {
                return true;
            }
            foreach ($entry['aliases'] as $alias) {
                $cleanAlias = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', strtolower($alias));
                $cleanAlias = preg_replace('/\s+/', ' ', trim((string) $cleanAlias));

                if ($clean === $cleanAlias || str_starts_with($clean, $cleanAlias) || str_contains($clean, $cleanAlias)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Resolve a query to a known canonical Railway Station Location DTO if matched.
     */
    public function resolve(string $query): ?Location
    {
        $normalized = strtolower(trim($query));
        $clean = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $normalized);
        $clean = preg_replace('/\s+/', ' ', trim((string) $clean));

        foreach ($this->stations as $code => $data) {
            // Check direct CRS code or name
            if (isset($data['crs']) && $clean === strtolower($data['crs'])) {
                return $this->buildLocation($query, $code, $data);
            }
            if ($clean === strtolower($data['name'])) {
                return $this->buildLocation($query, $code, $data);
            }

            // Check aliases
            foreach ($data['aliases'] as $alias) {
                $cleanAlias = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', strtolower($alias));
                $cleanAlias = preg_replace('/\s+/', ' ', trim((string) $cleanAlias));

                if ($clean === $cleanAlias || str_starts_with($clean, $cleanAlias) || str_contains($clean, $cleanAlias)) {
                    return $this->buildLocation($query, $code, $data);
                }
            }
        }

        return null;
    }

    /**
     * @param  array{name: string, crs?: string, formatted_address: string, lat: float, lon: float, city: string, postcode: string}  $data
     */
    private function buildLocation(string $query, string $code, array $data): Location
    {
        return new Location(
            query: $query,
            formattedAddress: $data['formatted_address'],
            coordinates: new Coordinates($data['lat'], $data['lon']),
            city: $data['city'],
            postcode: $data['postcode'],
            country: 'GB',
            placeId: 'station.'.$code,
        );
    }
}
