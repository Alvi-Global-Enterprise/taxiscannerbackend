<?php

declare(strict_types=1);

namespace App\Domain\Location\Services;

use App\Domain\Location\DTOs\Coordinates;
use App\Domain\Location\DTOs\Location;

class AirportLocationResolver
{
    /**
     * Known major UK commercial airports.
     * Clean and extensible: additional airports can also be configured via config/taxiscanner.php.
     *
     * @var array<string, array{name: string, iata: string, formatted_address: string, lat: float, lon: float, city: string, postcode: string, aliases: list<string>}>
     */
    private array $airports;

    /**
     * @param  array<string, array{name: string, iata: string, formatted_address: string, lat: float, lon: float, city: string, postcode: string, aliases: list<string>}>|null  $customAirports
     */
    public function __construct(?array $customAirports = null)
    {
        $defaults = [
            'man' => [
                'name' => 'Manchester Airport',
                'iata' => 'MAN',
                'formatted_address' => 'Manchester Airport (MAN), Ringway, Manchester, M90 1QX, United Kingdom',
                'lat' => 53.3588,
                'lon' => -2.2727,
                'city' => 'Manchester',
                'postcode' => 'M90 1QX',
                'aliases' => [
                    'manchester airport',
                    'manchester intl airport',
                    'manchester international airport',
                    'manchester airport terminal 1',
                    'manchester airport terminal 2',
                    'manchester airport terminal 3',
                    'man airport',
                ],
            ],
            'lhr' => [
                'name' => 'Heathrow Airport',
                'iata' => 'LHR',
                'formatted_address' => 'Heathrow Airport (LHR), Longford, Hounslow, TW6 1QG, United Kingdom',
                'lat' => 51.4700,
                'lon' => -0.4543,
                'city' => 'London',
                'postcode' => 'TW6 1QG',
                'aliases' => [
                    'heathrow airport',
                    'london heathrow',
                    'london heathrow airport',
                    'heathrow terminal 2',
                    'heathrow terminal 3',
                    'heathrow terminal 4',
                    'heathrow terminal 5',
                    'lhr airport',
                ],
            ],
            'lgw' => [
                'name' => 'Gatwick Airport',
                'iata' => 'LGW',
                'formatted_address' => 'Gatwick Airport (LGW), Horley, Gatwick, RH6 0NP, United Kingdom',
                'lat' => 51.1537,
                'lon' => -0.1821,
                'city' => 'London',
                'postcode' => 'RH6 0NP',
                'aliases' => [
                    'gatwick airport',
                    'london gatwick',
                    'london gatwick airport',
                    'gatwick north terminal',
                    'gatwick south terminal',
                    'lgw airport',
                ],
            ],
            'stn' => [
                'name' => 'Stansted Airport',
                'iata' => 'STN',
                'formatted_address' => 'Stansted Airport (STN), Bassingbourn Road, Stansted, CM24 1QW, United Kingdom',
                'lat' => 51.8860,
                'lon' => 0.2389,
                'city' => 'London',
                'postcode' => 'CM24 1QW',
                'aliases' => [
                    'stansted airport',
                    'london stansted',
                    'london stansted airport',
                    'stn airport',
                ],
            ],
            'ltn' => [
                'name' => 'Luton Airport',
                'iata' => 'LTN',
                'formatted_address' => 'Luton Airport (LTN), Airport Way, Luton, LU2 9LY, United Kingdom',
                'lat' => 51.8763,
                'lon' => -0.3717,
                'city' => 'London',
                'postcode' => 'LU2 9LY',
                'aliases' => [
                    'luton airport',
                    'london luton',
                    'london luton airport',
                    'ltn airport',
                ],
            ],
            'bhx' => [
                'name' => 'Birmingham Airport',
                'iata' => 'BHX',
                'formatted_address' => 'Birmingham Airport (BHX), Trident Way, Birmingham, B26 3QJ, United Kingdom',
                'lat' => 52.4539,
                'lon' => -1.7480,
                'city' => 'Birmingham',
                'postcode' => 'B26 3QJ',
                'aliases' => [
                    'birmingham airport',
                    'birmingham intl airport',
                    'birmingham international airport',
                    'bhx airport',
                ],
            ],
            'edi' => [
                'name' => 'Edinburgh Airport',
                'iata' => 'EDI',
                'formatted_address' => 'Edinburgh Airport (EDI), Edinburgh, EH12 9DN, United Kingdom',
                'lat' => 55.9508,
                'lon' => -3.3615,
                'city' => 'Edinburgh',
                'postcode' => 'EH12 9DN',
                'aliases' => [
                    'edinburgh airport',
                    'edi airport',
                ],
            ],
            'gla' => [
                'name' => 'Glasgow Airport',
                'iata' => 'GLA',
                'formatted_address' => 'Glasgow Airport (GLA), Paisley, PA3 2SW, United Kingdom',
                'lat' => 55.8719,
                'lon' => -4.4331,
                'city' => 'Glasgow',
                'postcode' => 'PA3 2SW',
                'aliases' => [
                    'glasgow airport',
                    'gla airport',
                ],
            ],
        ];

        $configured = $customAirports ?? config('taxiscanner.airports', []);
        $this->airports = array_merge($defaults, $configured);
    }

    /**
     * Determine if a query indicates an airport search.
     */
    public function isAirportQuery(string $query): bool
    {
        $lower = strtolower(trim($query));

        if (str_contains($lower, 'airport') || str_contains($lower, 'terminal')) {
            return true;
        }

        foreach ($this->airports as $entry) {
            if (strtolower($lower) === strtolower($entry['iata'])) {
                return true;
            }
            foreach ($entry['aliases'] as $alias) {
                if (str_contains($lower, $alias)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Resolve a query to a known airport terminal Location DTO if matched.
     */
    public function resolve(string $query): ?Location
    {
        $normalized = strtolower(trim($query));
        // Remove punctuation and extra whitespace for matching
        $clean = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $normalized);
        $clean = preg_replace('/\s+/', ' ', trim($clean));

        foreach ($this->airports as $code => $data) {
            // Check direct IATA code
            if ($clean === strtolower($data['iata']) || $clean === strtolower($data['name'])) {
                return $this->buildLocation($query, $code, $data);
            }

            // Check aliases
            foreach ($data['aliases'] as $alias) {
                $cleanAlias = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', strtolower($alias));
                $cleanAlias = preg_replace('/\s+/', ' ', trim($cleanAlias));

                if ($clean === $cleanAlias || str_starts_with($clean, $cleanAlias)) {
                    return $this->buildLocation($query, $code, $data);
                }
            }
        }

        return null;
    }

    /**
     * @param  array{name: string, iata: string, formatted_address: string, lat: float, lon: float, city: string, postcode: string}  $data
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
            placeId: 'airport.'.$code,
        );
    }
}
