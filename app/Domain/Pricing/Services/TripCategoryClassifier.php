<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Location\DTOs\Coordinates;
use App\Domain\Pricing\DTOs\PricingCalculationInput;

class TripCategoryClassifier
{
    /**
     * City centre coordinates (Manchester Town Hall / St Peter's Square).
     */
    private const CITY_CENTRE_LAT = 53.4808;

    private const CITY_CENTRE_LON = -2.2426;

    /**
     * Maximum radius (in miles) from city centre coords considered core city centre.
     */
    private const CITY_CENTRE_RADIUS_MILES = 1.5;

    /**
     * Default suburban transport hubs for orbital / inter-suburb route detection.
     * Extensible via config('taxiscanner.suburban_hubs').
     *
     * @var array<string, array{keywords: list<string>, postcodes: list<string>, center: array{0: float, 1: float}, radius_miles: float}>
     */
    private array $suburbanHubs;

    public function __construct(?array $customHubs = null)
    {
        $defaults = [
            'stockport' => [
                'keywords' => ['stockport', 'heaton norris', 'heaton chapel', 'edgeley', 'cheadle', 'bramhall', 'hazel grove', 'marple', 'reddish'],
                'postcodes' => ['SK1', 'SK2', 'SK3', 'SK4', 'SK5', 'SK7', 'SK8'],
                'center' => [53.4110, -2.1600],
                'radius_miles' => 4.5,
            ],
            'trafford' => [
                'keywords' => ['trafford park', 'nash road', 'trafford', 'urmston', 'stretford', 'flixton', 'davyhulme', 'sale', 'altrincham', 'timperley', 'hale', 'bowdon'],
                'postcodes' => ['M17', 'M32', 'M41', 'M33', 'WA14', 'WA15'],
                'center' => [53.4480, -2.3350],
                'radius_miles' => 4.5,
            ],
            'salford_west' => [
                'keywords' => ['eccles', 'worsley', 'swinton', 'walkden', 'pendlebury', 'monton', 'barton'],
                'postcodes' => ['M30', 'M27', 'M28'],
                'center' => [53.4830, -2.3360],
                'radius_miles' => 4.0,
            ],
            'bolton' => [
                'keywords' => ['bolton', 'horwich', 'farnworth', 'westhoughton'],
                'postcodes' => ['BL1', 'BL2', 'BL3', 'BL4', 'BL5', 'BL6'],
                'center' => [53.5780, -2.4290],
                'radius_miles' => 4.5,
            ],
            'bury' => [
                'keywords' => ['bury', 'radcliffe', 'prestwich', 'whitefield', 'ramsbottom'],
                'postcodes' => ['BL8', 'BL9', 'M26', 'M45'],
                'center' => [53.5930, -2.2980],
                'radius_miles' => 4.5,
            ],
            'rochdale' => [
                'keywords' => ['rochdale', 'heywood', 'middleton', 'littleborough', 'milnrow'],
                'postcodes' => ['OL11', 'OL12', 'OL16', 'M24'],
                'center' => [53.6170, -2.1550],
                'radius_miles' => 4.5,
            ],
            'oldham' => [
                'keywords' => ['oldham', 'chadderton', 'shaw', 'royton', 'failsworth', 'saddleworth'],
                'postcodes' => ['OL1', 'OL2', 'OL4', 'OL8', 'OL9', 'M35'],
                'center' => [53.5410, -2.1150],
                'radius_miles' => 4.5,
            ],
            'tameside' => [
                'keywords' => ['ashton-under-lyne', 'hyde', 'denton', 'stalybridge', 'dukinfield', 'mossley', 'audenshaw'],
                'postcodes' => ['OL6', 'OL7', 'SK14', 'SK15', 'SK16', 'M34', 'M43'],
                'center' => [53.4890, -2.0940],
                'radius_miles' => 4.5,
            ],
            'wigan' => [
                'keywords' => ['wigan', 'leigh', 'hindley', 'ashton-in-makerfield', 'atherton', 'tyldesley'],
                'postcodes' => ['WN1', 'WN2', 'WN3', 'WN4', 'WN5', 'WN6', 'WN7', 'M29'],
                'center' => [53.5450, -2.6320],
                'radius_miles' => 5.0,
            ],
            'cheshire_north' => [
                'keywords' => ['wilmslow', 'handforth', 'alderley edge', 'knutsford', 'macclesfield', 'poynton'],
                'postcodes' => ['SK9', 'WA16', 'SK10', 'SK11', 'SK12'],
                'center' => [53.3250, -2.2300],
                'radius_miles' => 5.0,
            ],
        ];

        $configured = $customHubs ?? config('taxiscanner.suburban_hubs', []);
        $this->suburbanHubs = array_merge($defaults, $configured);
    }

    /**
     * Classify trip category from PricingCalculationInput.
     */
    public function classifyFromInput(PricingCalculationInput $input): string
    {
        $pickup = $input->trip->pickupQuery ?: ($input->route->origin->formattedAddress ?? '');
        $dropoff = $input->trip->dropoffQuery ?: ($input->route->destination->formattedAddress ?? '');
        $distance = max(0.0, (float) $input->route->distanceMiles);

        $originCoords = $input->route->origin->coordinates ?? null;
        $destCoords = $input->route->destination->coordinates ?? null;

        $originPostcode = $input->route->origin->postcode ?? null;
        $destPostcode = $input->route->destination->postcode ?? null;

        return $this->classify(
            pickup: $pickup,
            dropoff: $dropoff,
            distanceMiles: $distance,
            originCoords: $originCoords,
            destCoords: $destCoords,
            originPostcode: $originPostcode,
            destPostcode: $destPostcode,
        );
    }

    /**
     * Classify trip category based on pickup, dropoff, distance, and optional geographic context.
     */
    public function classify(
        string $pickup,
        string $dropoff,
        float $distanceMiles,
        ?Coordinates $originCoords = null,
        ?Coordinates $destCoords = null,
        ?string $originPostcode = null,
        ?string $destPostcode = null,
    ): string {
        $airportPattern = '/\b(airport|aerodrome|terminal|MAN|LHR|LGW|STN|LTN|BHX|EDI|GLA)\b/i';

        $isPickupAirport = (bool) preg_match($airportPattern, $pickup);
        $isDropoffAirport = (bool) preg_match($airportPattern, $dropoff);

        // 1. Airport Transfers (e.g. Terminal 1 to Terminal 2)
        if ($isPickupAirport && $isDropoffAirport) {
            return 'airport_transfer';
        }

        // 2. Airport to City / Suburb
        if ($isPickupAirport) {
            return $this->isCityCenter($dropoff, $destCoords, $destPostcode) ? 'airport_to_city' : 'airport_to_suburb';
        }

        // 3. City / Suburb to Airport
        if ($isDropoffAirport) {
            return $this->isCityCenter($pickup, $originCoords, $originPostcode) ? 'city_to_airport' : 'airport_to_suburb';
        }

        // 4. Non-Airport Distance Categories
        if ($distanceMiles <= 4.0) {
            return 'city_short';
        }

        if ($distanceMiles > 15.0) {
            return 'city_long';
        }

        // 5. Non-Airport Trips between 4.0 and 15.0 miles:
        // Distinguish inter-suburb / orbital routes from radial Manchester city-centre routes
        if ($this->isInterSuburbRoute($pickup, $dropoff, $originCoords, $destCoords, $originPostcode, $destPostcode)) {
            return 'inter_suburb';
        }

        return 'city_medium';
    }

    /**
     * Check if a location is within Manchester City Centre core (radial pattern hub).
     */
    public function isCityCenter(
        string $text,
        ?Coordinates $coords = null,
        ?string $postcode = null,
    ): bool {
        $lower = strtolower(trim($text));

        // Keywords indicating central Manchester
        $cityKeywords = '/\b(city\s+centre|piccadilly|arndale|deansgate|portland\s+street|oxford\s+road\s+station|victoria\s+station|st\s+peter\'?s\s+square|spinningfields|northern\s+quarter|printworks|central\s+manchester|shudehill)\b/i';
        if (preg_match($cityKeywords, $lower)) {
            return true;
        }

        // Generic "Oxford Road" or "Victoria" in Manchester context
        if (str_contains($lower, 'oxford road') && str_contains($lower, 'manchester')) {
            return true;
        }
        if (str_contains($lower, 'victoria') && str_contains($lower, 'manchester') && ! str_contains($lower, 'victoria road')) {
            return true;
        }

        // Postcodes strictly identifying core city centre
        if ($postcode !== null) {
            $cleanPostcode = strtoupper(trim($postcode));
            if (preg_match('/^(M1|M2|M4|M60)\b/', $cleanPostcode)) {
                return true;
            }
        }

        // Core city centre coordinate proximity check (<= 1.5 miles)
        if ($coords !== null) {
            $centre = new Coordinates(self::CITY_CENTRE_LAT, self::CITY_CENTRE_LON);
            if ($centre->distanceToInMiles($coords) <= self::CITY_CENTRE_RADIUS_MILES) {
                // Confirm it has Manchester/Salford context and is not an outer borough
                if (str_contains($lower, 'manchester') || str_contains($lower, 'salford') || empty($lower)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Determine if a route represents an orbital/inter-suburb journey outside the city centre radial pattern.
     */
    public function isInterSuburbRoute(
        string $pickup,
        string $dropoff,
        ?Coordinates $originCoords = null,
        ?Coordinates $destCoords = null,
        ?string $originPostcode = null,
        ?string $destPostcode = null,
    ): bool {
        // Negative requirement: Neither pickup nor dropoff can be in Manchester City Centre
        if ($this->isCityCenter($pickup, $originCoords, $originPostcode)) {
            return false;
        }

        if ($this->isCityCenter($dropoff, $destCoords, $destPostcode)) {
            return false;
        }

        // Positive requirement: Both pickup and dropoff must match identifiable suburban transport hubs/zones
        $originHub = $this->resolveSuburbanHub($pickup, $originCoords, $originPostcode);
        $destHub = $this->resolveSuburbanHub($dropoff, $destCoords, $destPostcode);

        // Orbital journeys occur between two distinct suburban hubs (e.g. Stockport -> Trafford Park)
        if ($originHub !== null && $destHub !== null && $originHub !== $destHub) {
            return true;
        }

        return false;
    }

    /**
     * Resolve a location to a recognized suburban hub/zone.
     */
    public function resolveSuburbanHub(
        string $text,
        ?Coordinates $coords = null,
        ?string $postcode = null,
    ): ?string {
        $lower = strtolower(trim($text));

        foreach ($this->suburbanHubs as $hubKey => $hubData) {
            // 1. Postcode check
            if ($postcode !== null) {
                $cleanPostcode = strtoupper(trim($postcode));
                foreach ($hubData['postcodes'] as $prefix) {
                    if (str_starts_with($cleanPostcode, strtoupper($prefix))) {
                        return $hubKey;
                    }
                }
            }

            // Also check text for postcode pattern if postcode argument was not explicitly extracted
            foreach ($hubData['postcodes'] as $prefix) {
                if (preg_match('/\b'.preg_quote($prefix, '/').'\d*\b/i', $text)) {
                    return $hubKey;
                }
            }

            // 2. Keyword check
            foreach ($hubData['keywords'] as $kw) {
                if (str_contains($lower, strtolower($kw))) {
                    return $hubKey;
                }
            }

            // 3. Coordinate check (requires contextual place evidence to avoid false matches on dummy test queries)
            if ($coords !== null && isset($hubData['center']) && ! empty($lower) && strlen($lower) > 2) {
                $hubCenter = new Coordinates($hubData['center'][0], $hubData['center'][1]);
                $radius = $hubData['radius_miles'] ?? 4.0;
                if ($hubCenter->distanceToInMiles($coords) <= $radius) {
                    // Require that the text contains the hub key or a regional locality indicator
                    $localityPattern = '/\b('.preg_quote($hubKey, '/').'|stockport|trafford|cheshire|salford|bolton|bury|rochdale|oldham|wigan|tameside)\b/i';
                    if (preg_match($localityPattern, $lower)) {
                        return $hubKey;
                    }
                }
            }
        }

        return null;
    }
}
