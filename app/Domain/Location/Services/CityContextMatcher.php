<?php

declare(strict_types=1);

namespace App\Domain\Location\Services;

class CityContextMatcher
{
    /**
     * Common UK cities, metropolitan boroughs, and major regions.
     *
     * @var list<string>
     */
    private const UK_CITIES = [
        'Manchester',
        'Greater Manchester',
        'Salford',
        'Stockport',
        'Bolton',
        'Oldham',
        'Rochdale',
        'Bury',
        'Wigan',
        'Trafford',
        'Tameside',
        'Handforth',
        'Wilmslow',
        'Altrincham',
        'Sale',
        'Cheadle',
        'London',
        'Greater London',
        'Hounslow',
        'Hillingdon',
        'Westminster',
        'Camden',
        'Islington',
        'Kensington',
        'Chelsea',
        'Greenwich',
        'Southwark',
        'Lambeth',
        'Wandsworth',
        'Ealing',
        'Brent',
        'Harrow',
        'Barnet',
        'Richmond',
        'Kingston',
        'Merton',
        'Sutton',
        'Croydon',
        'Bromley',
        'Lewisham',
        'Bexley',
        'Havering',
        'Barking',
        'Dagenham',
        'Redbridge',
        'Newham',
        'Waltham Forest',
        'Haringey',
        'Enfield',
        'Birmingham',
        'West Midlands',
        'Leeds',
        'Liverpool',
        'Merseyside',
        'Whiston',
        'Prescot',
        'St Helens',
        'Knowsley',
        'Wirral',
        'Sefton',
        'Southport',
        'Bootle',
        'Glasgow',
        'Edinburgh',
        'Bristol',
        'Sheffield',
        'Newcastle',
        'Nottingham',
        'Cardiff',
        'Belfast',
        'Southampton',
        'Leicester',
        'Norwich',
        'Oxford',
        'Cambridge',
        'York',
        'Coventry',
        'Bradford',
        'Preston',
        'Derby',
        'Hull',
        'Plymouth',
        'Wolverhampton',
        'Swansea',
        'Aberdeen',
        'Dundee',
        'Milton Keynes',
        'Reading',
        'Northampton',
        'Luton',
        'Swindon',
        'Blackpool',
        'Middlesbrough',
        'Peterborough',
        'Sunderland',
    ];

    /**
     * Detect an explicit UK city or town context in the user query.
     */
    public function detectCityContext(string $query): ?string
    {
        $normalized = trim($query);

        // Check word boundaries for cities
        foreach (self::UK_CITIES as $city) {
            $pattern = '/\b'.preg_quote($city, '/').'\b/i';
            if (preg_match($pattern, $normalized)) {
                // If it matched a borough like Salford/Stockport, map Greater Manchester context if needed
                return $city;
            }
        }

        return null;
    }

    /**
     * Generate refined search query variations when a query contains ambiguous syntax
     * such as parentheses, descriptors like "hotel", "City Centre", etc.
     *
     * E.g. "Manchester City Centre (Portland Street) hotel"
     * -> ["Portland Street hotel, Manchester", "Portland Street, Manchester", "Portland Street, Manchester, UK"]
     *
     * @return list<string>
     */
    public function generateRefinedQueries(string $rawQuery, ?string $cityContext): array
    {
        $refined = [];
        $city = $cityContext ?? $this->detectCityContext($rawQuery);

        // 1. Handle Parenthesized queries: "Prefix (Inner) Suffix"
        if (preg_match('/^(.*?)\((.*?)\)(.*)$/u', $rawQuery, $matches)) {
            $prefix = trim($matches[1]);
            $inner = trim($matches[2]);
            $suffix = trim($matches[3]);

            $specific = trim($inner.' '.$suffix);

            if ($city !== null) {
                $refined[] = sprintf('%s, %s', $specific, $city);
                $refined[] = sprintf('%s, %s', $inner, $city);
                $refined[] = sprintf('%s, %s, UK', $specific, $city);
            }

            // Also test stripped parenthetical format: "Prefix Inner Suffix"
            $cleaned = trim(preg_replace('/\s+/', ' ', str_replace(['(', ')'], ' ', $rawQuery)));
            if (! empty($cleaned) && $cleaned !== $rawQuery) {
                $refined[] = $cleaned;
            }
        }

        // 2. If query mentions "City Centre", try removing "City Centre" or re-ordering with explicit city
        if (stripos($rawQuery, 'City Centre') !== false && $city !== null) {
            $withoutCityCentre = trim(preg_replace('/\bCity\s+Centre\b/i', '', $rawQuery));
            $withoutCityCentre = trim(preg_replace('/[(),]/', ' ', $withoutCityCentre));
            $withoutCityCentre = trim(preg_replace('/\s+/', ' ', $withoutCityCentre));

            // Remove duplicate mention of the city if already present in specific part
            $withoutCity = trim(preg_replace('/\b'.preg_quote($city, '/').'\b/i', '', $withoutCityCentre));
            $withoutCity = trim(preg_replace('/\s+/', ' ', $withoutCity));

            if (! empty($withoutCity)) {
                $refined[] = sprintf('%s, %s', $withoutCity, $city);
            }
        }

        // 3. Comma-separated query with nested/stacked city names: e.g. "Salford Quays, Salford, Manchester"
        if (str_contains($rawQuery, ',')) {
            $parts = array_values(array_filter(array_map('trim', explode(',', $rawQuery))));
            if (count($parts) >= 2) {
                $primary = $parts[0];

                // If query has 3 or more components, e.g. "Street, Locality, Town, UK"
                if (count($parts) >= 3) {
                    $refined[] = sprintf('%s, %s', $parts[0], $parts[1]);
                    $refined[] = sprintf('%s, %s', $parts[1], $parts[2]);
                    $refined[] = $parts[1];
                }

                if (! empty($primary)) {
                    if ($city !== null && strcasecmp($primary, $city) !== 0) {
                        $refined[] = sprintf('%s, %s', $primary, $city);
                    }
                    $refined[] = $primary;
                }
            }
        }

        // 4. Known major commercial landmarks with specific postcodes/localities: Trafford Centre
        if (preg_match('/\btrafford\s+centre\b/i', $rawQuery)) {
            $refined[] = 'The Trafford Centre, M17 8AA';
            $refined[] = 'The Trafford Centre, Regent Crescent, Trafford Park';
            $refined[] = 'The Trafford Centre, Manchester';
        }

        return array_values(array_unique(array_filter($refined, fn ($q) => $q !== $rawQuery)));
    }

    /**
     * Extract administrative place, locality, district, or neighborhood names from a feature.
     * Street names (accuracy: street/address) are explicitly excluded from city context.
     *
     * @param  array<string, mixed>  $feature
     * @return list<string>
     */
    public function getAdministrativeNames(array $feature): array
    {
        $names = [];

        // 1. If feature itself is an administrative type (place, locality, district, neighborhood, region)
        $placeTypes = $feature['place_type'] ?? [];
        if (is_array($placeTypes)) {
            foreach ($placeTypes as $pt) {
                if (in_array($pt, ['place', 'locality', 'district', 'neighborhood', 'region'], true)) {
                    $text = strtolower(trim((string) ($feature['text'] ?? '')));
                    if (! empty($text)) {
                        $names[] = $text;
                    }
                    break;
                }
            }
        }

        // 2. Context items representing administrative entities
        $context = $feature['context'] ?? [];
        if (is_array($context)) {
            foreach ($context as $item) {
                $id = (string) ($item['id'] ?? '');
                if (str_starts_with($id, 'place') || str_starts_with($id, 'locality') ||
                    str_starts_with($id, 'district') || str_starts_with($id, 'neighborhood') ||
                    str_starts_with($id, 'region')) {
                    $text = strtolower(trim((string) ($item['text'] ?? '')));
                    if (! empty($text)) {
                        $names[] = $text;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($names)));
    }

    /**
     * Validate whether a candidate feature/location matches the expected city context.
     * Does NOT silently accept an obviously wrong city.
     *
     * @param  array<string, mixed>  $feature
     */
    public function matchesCityContext(array $feature, string $expectedCity): bool
    {
        $expected = strtolower(trim($expectedCity));
        $adminNames = $this->getAdministrativeNames($feature);

        // 1. Check direct match against feature administrative entities
        foreach ($adminNames as $admin) {
            if ($admin === $expected || str_contains($admin, $expected) || str_contains($expected, $admin)) {
                return true;
            }
        }

        // 2. Check metropolitan area grouping (e.g. Salford, Trafford, Stockport within Greater Manchester)
        if ($this->isSameMetropolitanArea($expected, $adminNames)) {
            return true;
        }

        // Fallback for postcode features or features without detailed context:
        // Only accept if place_name contains explicit comma-separated city token (not street name)
        $placeName = strtolower((string) ($feature['place_name'] ?? ''));
        if (preg_match('/,\s*'.preg_quote($expected, '/').'\s*(,|$)/i', $placeName)) {
            return true;
        }

        return false;
    }

    /**
     * Check if feature administrative entities belong to the same metropolitan area
     * (e.g. Salford/Stockport/Trafford within Greater Manchester).
     *
     * @param  list<string>  $adminNames
     */
    private function isSameMetropolitanArea(string $expectedCity, array $adminNames): bool
    {
        $metroRegions = [
            'manchester' => [
                'manchester', 'salford', 'stockport', 'bolton', 'bury', 'oldham',
                'rochdale', 'trafford', 'tameside', 'wigan', 'greater manchester',
                'altrincham', 'sale', 'cheadle',
            ],
            'wilmslow' => [
                'wilmslow', 'handforth',
            ],
            'london' => [
                'london', 'greater london', 'hounslow', 'hillingdon', 'westminster',
                'camden', 'islington', 'kensington', 'chelsea', 'greenwich',
                'southwark', 'lambeth', 'wandsworth', 'ealing', 'brent',
                'harrow', 'barnet', 'richmond', 'kingston', 'merton',
                'sutton', 'croydon', 'bromley', 'lewisham', 'bexley',
                'havering', 'barking', 'dagenham', 'redbridge', 'newham',
                'waltham forest', 'haringey', 'enfield', 'city of london',
            ],
            'merseyside' => [
                'liverpool', 'merseyside', 'whiston', 'prescot', 'st helens',
                'knowsley', 'wirral', 'sefton', 'southport', 'bootle',
            ],
        ];

        $expectedLower = strtolower($expectedCity);

        foreach ($metroRegions as $region => $boroughs) {
            if (in_array($expectedLower, $boroughs, true) || $expectedLower === $region) {
                foreach ($adminNames as $admin) {
                    if (in_array($admin, $boroughs, true) || $admin === $region) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
