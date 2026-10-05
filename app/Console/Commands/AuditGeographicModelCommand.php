<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Location\DTOs\Coordinates;
use App\Domain\Location\DTOs\Location;
use App\Domain\Location\DTOs\RouteInformation;
use App\Domain\Pricing\DTOs\PricingCalculationInput;
use App\Domain\Pricing\Services\StreetCarsGeographicAdjustmentService;
use App\Domain\Pricing\Strategies\StreetCarsPricingStrategy;
use App\Domain\Taxi\DTOs\TripRequest;
use App\Domain\Taxi\Enums\TaxiProvider;
use App\Domain\Taxi\Models\ProviderPricingConfig;
use Illuminate\Console\Command;

class AuditGeographicModelCommand extends Command
{
    protected $signature = 'taxiscanner:audit:geographic';

    protected $description = 'Perform a read-only boundary, coverage, and overlap safety audit of StreetCars geographic calibration zones';

    public function handle(StreetCarsGeographicAdjustmentService $geoService): int
    {
        $this->info('================================================================================');
        $this->info('  STREETCARS GEOGRAPHIC CALIBRATION MODEL: BOUNDARY & SAFETY AUDIT (READ-ONLY)  ');
        $this->info('================================================================================');
        $this->comment('Analyzing empirical calibration zones, boundary overlap, coordinate radii,');
        $this->comment('keyword false positives, and category isolation. No database changes will be made.');
        $this->newLine();

        $zones = $geoService->getZones();

        // ---------------------------------------------------------------------
        // 1. Pairwise Geometric Radii Overlap Analysis
        // ---------------------------------------------------------------------
        $this->info('--- 1. Geometric Radii & Spatial Overlap Analysis ---');
        $overlapRows = [];
        $zoneKeys = array_keys($zones);

        for ($i = 0; $i < count($zoneKeys); $i++) {
            for ($j = $i + 1; $j < count($zoneKeys); $j++) {
                $k1 = $zoneKeys[$i];
                $k2 = $zoneKeys[$j];
                $z1 = $zones[$k1];
                $z2 = $zones[$k2];

                $c1 = new Coordinates((float) $z1['center']['latitude'], (float) $z1['center']['longitude']);
                $c2 = new Coordinates((float) $z2['center']['latitude'], (float) $z2['center']['longitude']);

                $dist = $c1->distanceToInMiles($c2);
                $sumRadius = (float) $z1['radius_miles'] + (float) $z2['radius_miles'];
                $overlaps = $dist < $sumRadius;
                $overlapDepth = $overlaps ? round($sumRadius - $dist, 2) : 0.0;

                $higherPriorityZone = ($z1['priority'] >= $z2['priority'])
                    ? sprintf('%s (p=%d)', $k1, $z1['priority'])
                    : sprintf('%s (p=%d)', $k2, $z2['priority']);

                $overlapRows[] = [
                    $k1.' vs '.$k2,
                    number_format($dist, 2).' mi',
                    number_format($sumRadius, 2).' mi',
                    $overlaps ? '<fg=yellow>YES (+'.number_format($overlapDepth, 2).' mi)</>' : '<fg=green>NO</>',
                    $higherPriorityZone,
                ];
            }
        }

        $this->table(
            ['Zone Pair', 'Center Dist', 'Combined Radii', 'Geometric Overlap', 'Precedence Winner'],
            $overlapRows
        );
        $this->newLine();

        // ---------------------------------------------------------------------
        // 2. Boundary Test Destinations Audit
        // ---------------------------------------------------------------------
        $this->info('--- 2. Representative Destination Coverage & Boundary Audit ---');

        $testDestinations = $this->getAuditDestinations();
        $destRows = [];

        foreach ($testDestinations as $item) {
            $coords = $item['coords'];
            $match = $geoService->matchZone(
                address: $item['address'],
                query: $item['query'],
                coordinates: $coords,
                postcode: $item['postcode'] ?? null,
            );

            $matchedZone = $match ? $match->zoneKey : 'FALLBACK';
            $conf = $match ? number_format($match->confidence, 2) : '0.00';
            $mult = $match ? number_format($match->multiplier, 4) : '1.0000';
            $isFallback = ($matchedZone === 'FALLBACK' || $mult === '1.0000') ? 'YES' : 'NO';
            $reason = $match ? $match->matchReason : 'No confident match (< 0.60 threshold)';

            $statusColor = 'green';
            if ($item['expected_behavior'] === 'FALLBACK' && $matchedZone !== 'FALLBACK') {
                $statusColor = 'red'; // Unintended match / false positive
            } elseif ($item['expected_behavior'] !== 'FALLBACK' && $matchedZone !== $item['expected_behavior']) {
                $statusColor = 'yellow'; // Matched unexpected zone
            }

            $destRows[] = [
                $item['category'],
                mb_strimwidth($item['name'], 0, 24),
                sprintf('(%.3f, %.3f)', $coords->latitude, $coords->longitude),
                sprintf('<fg=%s>%s</>', $statusColor, $matchedZone),
                $conf,
                $mult,
                $isFallback,
                mb_strimwidth($reason, 0, 34, '...'),
            ];
        }

        $this->table(
            ['Type', 'Destination', 'Resolved Coords', 'Matched Zone', 'Conf', 'Multiplier', 'Fallback', 'Match Reason'],
            $destRows
        );
        $this->newLine();

        // ---------------------------------------------------------------------
        // 3. Category Isolation Audit (Non-Suburb Trips)
        // ---------------------------------------------------------------------
        $this->info('--- 3. Category Isolation Audit (Non-Suburb Trips) ---');
        $this->comment('Verifying that airport_to_city, city_long, city_medium, city_short, and city_to_airport never enter geographic adjustment.');

        $categoryTestResults = $this->auditCategoryIsolation($geoService);
        $this->table(
            ['Trip Name', 'Pickup -> Dropoff', 'Resolved Category', 'Multiplier Applied', 'Geo Model Entered?'],
            $categoryTestResults
        );
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getAuditDestinations(): array
    {
        return [
            // Core Zone Inliers
            [
                'category' => 'Inlier',
                'name' => 'Handforth Town Centre',
                'query' => 'Handforth Centre, Wilmslow',
                'address' => 'Handforth, Wilmslow, SK9 3AB, UK',
                'coords' => new Coordinates(53.354, -2.214),
                'postcode' => 'SK9 3AB',
                'expected_behavior' => 'handforth_wilmslow',
            ],
            [
                'category' => 'Inlier',
                'name' => 'Wilmslow High Street',
                'query' => 'Water Lane, Wilmslow',
                'address' => 'Water Lane, Wilmslow, SK9 1PB, UK',
                'coords' => new Coordinates(53.326, -2.235),
                'postcode' => 'SK9 1PB',
                'expected_behavior' => 'handforth_wilmslow',
            ],
            [
                'category' => 'Inlier',
                'name' => 'Urmston Shopping Hub',
                'query' => 'Urmston, Greater Manchester',
                'address' => 'Urmston, Manchester, M41 9AE, UK',
                'coords' => new Coordinates(53.448, -2.355),
                'postcode' => 'M41 9AE',
                'expected_behavior' => 'trafford_north',
            ],
            [
                'category' => 'Inlier',
                'name' => 'Stretford Mall',
                'query' => 'Stretford Mall, Chester Road',
                'address' => 'Chester Road, Stretford, M32 9BD, UK',
                'coords' => new Coordinates(53.447, -2.312),
                'postcode' => 'M32 9BD',
                'expected_behavior' => 'trafford_north',
            ],
            [
                'category' => 'Inlier',
                'name' => 'Altrincham George Street',
                'query' => 'George Street, Altrincham',
                'address' => 'George Street, Altrincham, WA14 1EP, UK',
                'coords' => new Coordinates(53.387, -2.355),
                'postcode' => 'WA14 1EP',
                'expected_behavior' => 'altrincham_sale_stockport',
            ],
            [
                'category' => 'Inlier',
                'name' => 'Sale Town Centre',
                'query' => 'School Road, Sale',
                'address' => 'School Road, Sale, M33 7WZ, UK',
                'coords' => new Coordinates(53.424, -2.324),
                'postcode' => 'M33 7WZ',
                'expected_behavior' => 'altrincham_sale_stockport',
            ],
            [
                'category' => 'Inlier',
                'name' => 'Stockport Merseyway',
                'query' => 'Merseyway Shopping Centre',
                'address' => 'Merseyway, Stockport, SK1 1QE, UK',
                'coords' => new Coordinates(53.411, -2.160),
                'postcode' => 'SK1 1QE',
                'expected_behavior' => 'altrincham_sale_stockport',
            ],
            [
                'category' => 'Inlier',
                'name' => 'Cheadle High Street',
                'query' => 'High Street, Cheadle',
                'address' => 'High Street, Cheadle, SK8 1AX, UK',
                'coords' => new Coordinates(53.393, -2.214),
                'postcode' => 'SK8 1AX',
                'expected_behavior' => 'cheadle',
            ],
            [
                'category' => 'Inlier',
                'name' => 'Knutsford Princess St',
                'query' => 'Princess Street, Knutsford',
                'address' => 'Princess Street, Knutsford, WA16 6BX, UK',
                'coords' => new Coordinates(53.304, -2.375),
                'postcode' => 'WA16 6BX',
                'expected_behavior' => 'knutsford',
            ],
            [
                'category' => 'Inlier',
                'name' => 'Macclesfield Market',
                'query' => 'Market Place, Macclesfield',
                'address' => 'Market Place, Macclesfield, SK10 1EB, UK',
                'coords' => new Coordinates(53.258, -2.126),
                'postcode' => 'SK10 1EB',
                'expected_behavior' => 'macclesfield',
            ],

            // Boundary / Overlapping Destinations
            [
                'category' => 'Border',
                'name' => 'Cheadle Hulme',
                'query' => 'Station Road, Cheadle Hulme',
                'address' => 'Station Road, Cheadle Hulme, SK8 7AA, UK',
                'coords' => new Coordinates(53.376, -2.188),
                'postcode' => 'SK8 7AA',
                'expected_behavior' => 'cheadle',
            ],
            [
                'category' => 'Border',
                'name' => 'Gatley Village',
                'query' => 'Gatley, Cheadle',
                'address' => 'Gatley, Cheadle, SK8 4AB, UK',
                'coords' => new Coordinates(53.395, -2.235),
                'postcode' => 'SK8 4AB',
                'expected_behavior' => 'cheadle',
            ],
            [
                'category' => 'Border',
                'name' => 'Heald Green',
                'query' => 'Finney Lane, Heald Green',
                'address' => 'Finney Lane, Heald Green, SK8 3AB, UK',
                'coords' => new Coordinates(53.371, -2.222),
                'postcode' => 'SK8 3AB',
                'expected_behavior' => 'cheadle',
            ],
            [
                'category' => 'Border',
                'name' => 'Mobberley (Knutsford border)',
                'query' => 'Town Lane, Mobberley',
                'address' => 'Town Lane, Mobberley, Knutsford, WA16 7AB, UK',
                'coords' => new Coordinates(53.318, -2.318),
                'postcode' => 'WA16 7AB',
                'expected_behavior' => 'knutsford',
            ],
            [
                'category' => 'Border',
                'name' => 'Alderley Edge (Wilmslow border)',
                'query' => 'London Road, Alderley Edge',
                'address' => 'London Road, Alderley Edge, SK9 7AB, UK',
                'coords' => new Coordinates(53.303, -2.235),
                'postcode' => 'SK9 7AB',
                'expected_behavior' => 'handforth_wilmslow',
            ],
            [
                'category' => 'Border',
                'name' => 'Bramhall (Stockport Borough)',
                'query' => 'Bramhall Lane South, Bramhall',
                'address' => 'Bramhall, Stockport, SK7 1AB, UK',
                'coords' => new Coordinates(53.360, -2.165),
                'postcode' => 'SK7 1AB',
                'expected_behavior' => 'altrincham_sale_stockport',
            ],
            [
                'category' => 'Border',
                'name' => 'Poynton (Cheshire East)',
                'query' => 'Park Lane, Poynton',
                'address' => 'Park Lane, Poynton, Stockport, SK12 1AB, UK',
                'coords' => new Coordinates(53.348, -2.125),
                'postcode' => 'SK12 1AB',
                'expected_behavior' => 'altrincham_sale_stockport',
            ],
            [
                'category' => 'Border',
                'name' => 'Hale Barns (Trafford South)',
                'query' => 'Hale Road, Hale Barns',
                'address' => 'Hale Barns, Altrincham, WA15 0AB, UK',
                'coords' => new Coordinates(53.368, -2.335),
                'postcode' => 'WA15 0AB',
                'expected_behavior' => 'altrincham_sale_stockport',
            ],
            [
                'category' => 'Border',
                'name' => 'Partington (Trafford West)',
                'query' => 'Partington, Greater Manchester',
                'address' => 'Partington, Manchester, M31 4AB, UK',
                'coords' => new Coordinates(53.418, -2.430),
                'postcode' => 'M31 4AB',
                'expected_behavior' => 'FALLBACK',
            ],
            [
                'category' => 'Border',
                'name' => 'Carrington (Trafford West)',
                'query' => 'Manchester Road, Carrington',
                'address' => 'Carrington, Manchester, M31 4XN, UK',
                'coords' => new Coordinates(53.435, -2.400),
                'postcode' => 'M31 4XN',
                'expected_behavior' => 'FALLBACK',
            ],

            // Non-Zone Suburbs / False Positive Sensitivity Checks
            [
                'category' => 'Sensitivity',
                'name' => 'Wythenshawe (No KW match)',
                'query' => 'Poundswick Lane, Wythenshawe',
                'address' => 'Poundswick Lane, Wythenshawe, Manchester, M22 9TA, UK',
                'coords' => new Coordinates(53.381, -2.262),
                'postcode' => 'M22 9TA',
                'expected_behavior' => 'FALLBACK',
            ],
            [
                'category' => 'Inlier',
                'name' => 'Didsbury (Wilmslow Rd)',
                'query' => 'Wilmslow Road, Didsbury',
                'address' => 'Wilmslow Road, Didsbury, Manchester, M20 2RN, UK',
                'coords' => new Coordinates(53.417, -2.231),
                'postcode' => 'M20 2RN',
                'expected_behavior' => 'didsbury',
            ],
            [
                'category' => 'Sensitivity',
                'name' => 'Chorlton-cum-Hardy',
                'query' => 'Barlow Moor Road, Chorlton',
                'address' => 'Barlow Moor Road, Chorlton, Manchester, M21 8AY, UK',
                'coords' => new Coordinates(53.442, -2.277),
                'postcode' => 'M21 8AY',
                'expected_behavior' => 'FALLBACK',
            ],
            [
                'category' => 'Sensitivity',
                'name' => 'Eccles Town Centre',
                'query' => 'Church Street, Eccles',
                'address' => 'Church Street, Eccles, Manchester, M30 0DF, UK',
                'coords' => new Coordinates(53.483, -2.336),
                'postcode' => 'M30 0DF',
                'expected_behavior' => 'FALLBACK',
            ],
            [
                'category' => 'Sensitivity',
                'name' => 'Salford Urban Core',
                'query' => 'Broad Street, Salford',
                'address' => 'Broad Street, Salford, M6 5FW, UK',
                'coords' => new Coordinates(53.487, -2.289),
                'postcode' => 'M6 5FW',
                'expected_behavior' => 'FALLBACK',
            ],
            [
                'category' => 'Sensitivity',
                'name' => 'Manchester Airport Perimeter',
                'query' => 'Atlanta Way, Manchester Airport',
                'address' => 'Atlanta Way, Ringway, Manchester, M90 4ES, UK',
                'coords' => new Coordinates(53.365, -2.268),
                'postcode' => 'M90 4ES',
                'expected_behavior' => 'FALLBACK',
            ],
            [
                'category' => 'Sensitivity',
                'name' => 'Bolton Town Hall',
                'query' => 'Victoria Square, Bolton',
                'address' => 'Victoria Square, Bolton, BL1 1RU, UK',
                'coords' => new Coordinates(53.578, -2.429),
                'postcode' => 'BL1 1RU',
                'expected_behavior' => 'FALLBACK',
            ],
            [
                'category' => 'Sensitivity',
                'name' => 'Warrington Town Centre',
                'query' => 'Horsemarket Street, Warrington',
                'address' => 'Horsemarket Street, Warrington, WA1 1TS, UK',
                'coords' => new Coordinates(53.390, -2.597),
                'postcode' => 'WA1 1TS',
                'expected_behavior' => 'FALLBACK',
            ],
            [
                'category' => 'Sensitivity',
                'name' => 'Northwich Centre',
                'query' => 'High Street, Northwich',
                'address' => 'High Street, Northwich, CW9 5BN, UK',
                'coords' => new Coordinates(53.258, -2.518),
                'postcode' => 'CW9 5BN',
                'expected_behavior' => 'FALLBACK',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditCategoryIsolation(StreetCarsGeographicAdjustmentService $geoService): array
    {
        $strategy = new StreetCarsPricingStrategy($geoService);
        $config = ProviderPricingConfig::where('provider_id', 3)->where('is_active', true)->first();

        $trips = [
            'Airport -> Piccadilly' => ['Manchester Airport (MAN)', 'Manchester Piccadilly Station', 9.5, 25],
            'Airport -> Arndale' => ['Manchester Airport (MAN)', 'Manchester Arndale Centre, Market Street', 10.0, 26],
            'Airport -> Deansgate' => ['Manchester Airport (MAN)', 'Deansgate, Manchester City Centre', 9.2, 24],
            'Airport -> Portland St' => ['Manchester Airport (MAN)', 'Portland Street, Manchester City Centre', 9.4, 25],
            'Piccadilly -> Airport' => ['Manchester Piccadilly Station', 'Manchester Airport (MAN)', 9.5, 25],
            'Piccadilly -> Arndale' => ['Manchester Piccadilly Station', 'Manchester Arndale Centre', 0.8, 5],
            'Piccadilly -> Salford Quays' => ['Manchester Piccadilly Station', 'Salford Quays, Salford', 3.2, 12],
            'Piccadilly -> Cheshire Oaks' => ['Manchester Piccadilly Station', 'Designer Outlet Cheshire Oaks', 43.7, 64],
            'Airport T1 -> Airport T2' => ['Manchester Airport Terminal 1', 'Manchester Airport Terminal 2', 1.0, 5],
        ];

        $results = [];

        foreach ($trips as $name => $data) {
            $origin = new Location($data[0], $data[0], new Coordinates(53.477, -2.230));
            $dest = new Location($data[1], $data[1], new Coordinates(53.483, -2.242));
            $route = new RouteInformation($origin, $dest, (int) round($data[2] * 1609.34), (float) $data[2], (int) ($data[3] * 60), (int) $data[3]);
            $trip = new TripRequest($data[0], $data[1]);
            $input = new PricingCalculationInput(TaxiProvider::STREETCARS, $route, $trip, $config);

            $breakdown = $strategy->calculateBreakdown($input);
            $applied = $breakdown->extraItems['geographic_calibration_applied'] ?? false;
            $cat = $breakdown->extraItems['calibration_category'] ?? $breakdown->extraItems['trip_category'] ?? 'unclassified';
            $mult = $breakdown->calibrationMultiplier;

            $results[] = [
                $name,
                $data[0].' -> '.$data[1],
                $cat,
                number_format($mult, 4),
                $applied ? '<fg=red>YES (FAIL)</>' : '<fg=green>NO (SAFE)</>',
            ];
        }

        return $results;
    }
}
