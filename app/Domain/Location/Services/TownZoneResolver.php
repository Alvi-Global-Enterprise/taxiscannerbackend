<?php

declare(strict_types=1);

namespace App\Domain\Location\Services;

/**
 * Resolves pickup coordinates / text to UK coverage zone id(s).
 * Seamless layers: all_uk → nation → region → city.
 */
final class TownZoneResolver
{
    /** @var list<array<string, mixed>>|null */
    private static ?array $zones = null;

    private static ?int $zonesLoadedAt = null;

    private const LAYER_RANK = [
        'city' => 1,
        'region' => 2,
        'nation' => 3,
        'all_uk' => 4,
    ];

    /**
     * @return array{
     *   town_id: ?string,
     *   match_type: string,
     *   display_name: ?string,
     *   matching_ids: list<string>
     * }
     */
    public function resolve(
        ?float $lat,
        ?float $lon,
        ?string $locationName = null,
        ?string $query = null,
    ): array {
        $matches = $this->matchingZones($lat, $lon, $locationName, $query);

        if ($matches === []) {
            return [
                'town_id' => null,
                'match_type' => 'none',
                'display_name' => null,
                'matching_ids' => [],
            ];
        }

        $primary = $this->pickPrimary($matches);

        return [
            'town_id' => (string) $primary['id'],
            'match_type' => (string) ($primary['_match_type'] ?? 'polygon'),
            'display_name' => (string) ($primary['displayName'] ?? $primary['name']),
            'matching_ids' => array_values(array_unique(array_map(
                static fn (array $z): string => (string) $z['id'],
                $matches
            ))),
        ];
    }

    /**
     * @return list<string>
     */
    public function matchingTownIds(
        ?float $lat,
        ?float $lon,
        ?string $locationName = null,
        ?string $query = null,
    ): array {
        return $this->resolve($lat, $lon, $locationName, $query)['matching_ids'];
    }

    /**
     * @return list<array{id: string, name: string, displayName: string, region: string, country: string, layer: ?string}>
     */
    public function catalogue(): array
    {
        return array_map(static function (array $zone): array {
            return [
                'id' => (string) $zone['id'],
                'name' => (string) $zone['name'],
                'displayName' => (string) ($zone['displayName'] ?? $zone['name']),
                'region' => (string) ($zone['region'] ?? ''),
                'country' => (string) ($zone['country'] ?? ''),
                'layer' => isset($zone['layer']) ? (string) $zone['layer'] : null,
            ];
        }, $this->zones());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function matchingZones(
        ?float $lat,
        ?float $lon,
        ?string $locationName,
        ?string $query,
    ): array {
        $zones = $this->zones();
        $hits = [];

        if ($lat !== null && $lon !== null) {
            foreach ($zones as $zone) {
                /** @var list<array{0: float, 1: float}> $boundary */
                $boundary = $zone['boundary'] ?? [];
                if ($boundary !== [] && $this->pointInPolygon($lat, $lon, $boundary)) {
                    $zone['_match_type'] = 'polygon';
                    $hits[] = $zone;
                }
            }

            if ($hits === []) {
                $closest = null;
                $minDistance = INF;
                foreach ($zones as $zone) {
                    $layer = (string) ($zone['layer'] ?? 'city');
                    if ($layer === 'all_uk') {
                        continue;
                    }
                    $center = $zone['center'] ?? null;
                    if (! is_array($center) || count($center) < 2) {
                        continue;
                    }
                    $dist = $this->haversineKm($lat, $lon, (float) $center[0], (float) $center[1]);
                    $radius = (float) ($zone['radiusKm'] ?? 0);
                    if ($dist <= $radius && $dist < $minDistance) {
                        $minDistance = $dist;
                        $closest = $zone;
                    }
                }
                if ($closest !== null) {
                    $closest['_match_type'] = 'radius';
                    $hits[] = $closest;
                }
            }

            // Still nothing: nearest city/region within 80km (rural UK)
            if ($hits === []) {
                $nearest = null;
                $nearestDist = INF;
                foreach ($zones as $zone) {
                    $layer = (string) ($zone['layer'] ?? 'city');
                    if (! in_array($layer, ['city', 'region', 'nation'], true)) {
                        continue;
                    }
                    $center = $zone['center'] ?? null;
                    if (! is_array($center) || count($center) < 2) {
                        continue;
                    }
                    $dist = $this->haversineKm($lat, $lon, (float) $center[0], (float) $center[1]);
                    if ($dist < $nearestDist) {
                        $nearestDist = $dist;
                        $nearest = $zone;
                    }
                }
                if ($nearest !== null && $nearestDist <= 80.0) {
                    $nearest['_match_type'] = 'nearest';
                    $hits[] = $nearest;
                }
            }

            // Any UK hit also implies all_uk coverage key
            if ($hits !== []) {
                foreach ($zones as $zone) {
                    if (($zone['layer'] ?? null) === 'all_uk') {
                        $exists = false;
                        foreach ($hits as $h) {
                            if ((string) $h['id'] === 'all_uk') {
                                $exists = true;
                                break;
                            }
                        }
                        if (! $exists) {
                            $zone['_match_type'] = 'implied';
                            $hits[] = $zone;
                        }
                        break;
                    }
                }
            }
        }

        if ($hits === []) {
            $haystack = strtolower(trim(($locationName ?? '').' '.($query ?? '')));
            if ($haystack !== '') {
                foreach ($zones as $zone) {
                    foreach ($zone['keywords'] ?? [] as $keyword) {
                        if ($keyword !== '' && str_contains($haystack, strtolower((string) $keyword))) {
                            $zone['_match_type'] = 'keyword';
                            $hits[] = $zone;
                            break;
                        }
                    }
                }
            }
        }

        return $hits;
    }

    /**
     * @param  list<array<string, mixed>>  $matches
     * @return array<string, mixed>
     */
    private function pickPrimary(array $matches): array
    {
        usort($matches, function (array $a, array $b): int {
            $ra = self::LAYER_RANK[(string) ($a['layer'] ?? 'city')] ?? 9;
            $rb = self::LAYER_RANK[(string) ($b['layer'] ?? 'city')] ?? 9;

            return $ra <=> $rb;
        });

        return $matches[0];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function zones(): array
    {
        $path = config_path('data/uk_town_zones.json');
        $mtime = is_file($path) ? (int) filemtime($path) : 0;

        if (self::$zones !== null && self::$zonesLoadedAt === $mtime) {
            return self::$zones;
        }

        if (! is_file($path)) {
            self::$zones = [];
            self::$zonesLoadedAt = $mtime;

            return self::$zones;
        }

        /** @var list<array<string, mixed>>|null $decoded */
        $decoded = json_decode((string) file_get_contents($path), true);
        self::$zones = is_array($decoded) ? $decoded : [];
        self::$zonesLoadedAt = $mtime;

        return self::$zones;
    }

    /**
     * @param  list<array{0: float|int, 1: float|int}>  $polygon
     */
    private function pointInPolygon(float $lat, float $lng, array $polygon): bool
    {
        $inside = false;
        $count = count($polygon);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xi = (float) $polygon[$i][0];
            $yi = (float) $polygon[$i][1];
            $xj = (float) $polygon[$j][0];
            $yj = (float) $polygon[$j][1];

            $intersect = (($yi > $lng) !== ($yj > $lng))
                && ($lat < (($xj - $xi) * ($lng - $yi)) / (($yj - $yi) ?: 1e-12) + $xi);

            if ($intersect) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    private function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earth = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
