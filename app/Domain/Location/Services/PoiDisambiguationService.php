<?php

declare(strict_types=1);

namespace App\Domain\Location\Services;

use App\Domain\Location\DTOs\Coordinates;
use Psr\Log\LoggerInterface;

class PoiDisambiguationService
{
    /**
     * Common UK business chains, retail brands, and POI types that frequently lack location context.
     *
     * @var list<string>
     */
    private const KNOWN_GENERIC_BRANDS = [
        // Optical & Healthcare
        'vision express', 'specsavers', 'boots', 'superdrug', 'holland & barrett', 'holland and barrett',

        // Supermarkets & Groceries
        'tesco', 'tesco express', 'tesco extra', 'tesco superstore', 'tesco metro',
        'sainsbury\'s', 'sainsburys', 'sainsbury\'s local', 'sainsburys local',
        'asda', 'asda superstore', 'morrisons', 'aldi', 'lidl', 'waitrose',
        'marks & spencer', 'marks and spencer', 'm&s', 'co-op', 'coop', 'co-op food',

        // Food, Coffee & Fast Food
        'mcdonald\'s', 'mcdonalds', 'burger king', 'kfc', 'subway', 'greggs',
        'costa', 'costa coffee', 'starbucks', 'pret', 'pret a manger', 'nando\'s', 'nandos',
        'domino\'s', 'dominos', 'pizza hut', 'wagamama', 'wetherspoon', 'wetherspoons',

        // Hotels & Lodging
        'premier inn', 'travelodge', 'holiday inn', 'holiday inn express', 'hilton', 'marriott', 'ibis',

        // Retail & DIY
        'currys', 'argos', 'b&q', 'wickes', 'screwfix', 'halfords', 'primark', 'zara', 'h&m', 'next', 'tk maxx',

        // Petrol & Fuel
        'shell', 'bp', 'esso', 'texaco',
    ];

    /**
     * Address, road, and geographic qualifier tokens that indicate an explicitly qualified address.
     *
     * @var list<string>
     */
    private const ADDRESS_STREET_TOKENS = [
        'street', 'st', 'road', 'rd', 'avenue', 'ave', 'lane', 'ln', 'drive', 'dr',
        'way', 'close', 'cl', 'crescent', 'cres', 'court', 'ct', 'square', 'sq',
        'place', 'pl', 'boulevard', 'blvd', 'terrace', 'terr', 'gardens', 'gdns',
        'parkway', 'pkwy', 'parade', 'row', 'hill', 'walk', 'wharf', 'quay',
    ];

    public function __construct(
        private readonly CityContextMatcher $cityMatcher,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Determine if a user query is a generic POI/business name with no explicit address, city, or postcode.
     */
    public function isGenericPoiQuery(string $query): bool
    {
        $normalized = strtolower(trim($query));

        if (empty($normalized)) {
            return false;
        }

        // 1. Postcode check: Full UK postcode (e.g., TW6 1QG, M1 4GS, NR2 3LF, W1D 1BS)
        if (preg_match('/\b[A-Z]{1,2}[0-9][A-Z0-9]?\s*[0-9][A-Z]{2}\b/i', $query)) {
            return false;
        }

        // 2. City or borough check
        if ($this->cityMatcher->detectCityContext($query) !== null) {
            return false;
        }

        // 3. Comma-separated query with location qualifier (e.g. "Vision Express, Hounslow", "Tesco, Oxford Street")
        if (str_contains($query, ',')) {
            $parts = array_map('trim', explode(',', $query));
            if (count($parts) >= 2 && ! empty($parts[1])) {
                return false;
            }
        }

        // 4. Street / road keywords
        foreach (self::ADDRESS_STREET_TOKENS as $token) {
            if (preg_match('/\b'.preg_quote($token, '/').'\b/i', $query)) {
                return false;
            }
        }

        // 5. Street numbers or unit numbers (e.g., "181 Oxford Street", "Unit 4", "Terminal 2")
        if (preg_match('#\b\d+[-/]?\d*\b#', $query) || preg_match('/\bterminal\s+\d+\b/i', $query)) {
            return false;
        }

        // 6. Major transit hubs (airports, railway stations, terminals) are explicit transport hubs, not generic POIs
        if (preg_match('/\b(airport|aerodrome|airfield|station|railway|pier|ferry|terminal)\b/i', $query)) {
            return false;
        }

        // 7. Direct match with known generic brand list
        $cleanBrand = preg_replace('/[^\w\s\']/', '', $normalized);
        foreach (self::KNOWN_GENERIC_BRANDS as $brand) {
            if ($cleanBrand === $brand || str_starts_with($cleanBrand, $brand.' ')) {
                return true;
            }
        }

        // 8. Fallback: Short query (<= 3 words) without explicit address words
        $words = preg_split('/\s+/', trim($cleanBrand));
        if (count($words) <= 3) {
            return true;
        }

        return false;
    }

    /**
     * Score candidate features returned by geocoding API against query and pickup proximity.
     *
     * @param  list<array<string, mixed>>  $features
     * @return array{
     *     status: 'EXPLICIT'|'SELECTED'|'AMBIGUOUS_NO_CANDIDATE'|'AMBIGUOUS_MULTIPLE_CANDIDATES',
     *     selected_feature: array<string, mixed>|null,
     *     candidates: list<array<string, mixed>>,
     *     debug: array<string, mixed>
     * }
     */
    public function evaluateCandidates(
        string $rawQuery,
        array $features,
        ?Coordinates $proximity = null,
        ?string $referenceCity = null,
        float $maxPoiRadiusMiles = 35.0,
        float $confidenceThreshold = 0.60,
    ): array {
        $isGeneric = $this->isGenericPoiQuery($rawQuery);

        // If not a generic POI query (e.g., explicitly specified address, city, postcode),
        // candidate filtering by generic POI radius does NOT apply. Legitimate long-distance trips proceed.
        if (! $isGeneric) {
            $selected = $features[0] ?? null;

            return [
                'status' => 'EXPLICIT',
                'selected_feature' => $selected,
                'candidates' => $features,
                'debug' => [
                    'original_query' => $rawQuery,
                    'is_generic_poi' => false,
                    'candidate_count' => count($features),
                    'selected_candidate' => $selected['place_name'] ?? null,
                    'confidence_score' => 1.0,
                    'reason' => 'Explicit destination provided with address/city/postcode; generic POI constraints bypassed.',
                ],
            ];
        }

        if (empty($features)) {
            return [
                'status' => 'AMBIGUOUS_NO_CANDIDATE',
                'selected_feature' => null,
                'candidates' => [],
                'debug' => [
                    'original_query' => $rawQuery,
                    'is_generic_poi' => true,
                    'candidate_count' => 0,
                    'selected_candidate' => null,
                    'confidence_score' => 0.0,
                    'reason' => 'No candidates returned from geocoder.',
                ],
            ];
        }

        // Score each candidate
        $scored = [];
        foreach ($features as $feature) {
            $scoreData = $this->scoreFeature(
                feature: $feature,
                query: $rawQuery,
                proximity: $proximity,
                referenceCity: $referenceCity,
                maxPoiRadiusMiles: $maxPoiRadiusMiles,
            );

            // Filter out candidates with 0 confidence (e.g., completely out of bounds or unrelated name)
            if ($scoreData['confidence'] > 0.0) {
                $scored[] = $scoreData;
            }
        }

        // Sort scored candidates descending by confidence score
        usort($scored, fn ($a, $b) => $b['confidence'] <=> $a['confidence']);

        $candidateSummaries = array_map(function ($s) {
            return [
                'formatted_address' => $s['place_name'],
                'coordinates' => $s['coordinates']->toArray(),
                'distance_miles' => $s['distance_miles'],
                'confidence' => round($s['confidence'], 4),
                'name_match' => round($s['name_match_score'], 4),
            ];
        }, $scored);

        // Case 1: No valid candidate survived scoring and radius constraints
        if (empty($scored)) {
            $this->logger->warning('POI disambiguation: No candidates satisfied geographic and name constraints', [
                'query' => $rawQuery,
                'features_received' => count($features),
                'first_feature_rejected' => $features[0]['place_name'] ?? null,
                'first_feature_id' => $features[0]['id'] ?? null,
            ]);

            return [
                'status' => 'AMBIGUOUS_NO_CANDIDATE',
                'selected_feature' => null,
                'candidates' => [],
                'debug' => [
                    'original_query' => $rawQuery,
                    'is_generic_poi' => true,
                    'candidate_count' => count($features),
                    'valid_candidates_count' => 0,
                    'selected_candidate' => null,
                    'confidence_score' => 0.0,
                    'reason' => sprintf('All %d candidate(s) were rejected due to excessive distance (> %.1f miles) or unrelated name.', count($features), $maxPoiRadiusMiles),
                ],
            ];
        }

        $top = $scored[0];

        // Case 2: Top candidate meets or exceeds confidence threshold
        if ($top['confidence'] >= $confidenceThreshold) {
            // If multiple candidates exist, verify if top candidate is clearly superior
            if (count($scored) > 1) {
                $second = $scored[1];
                // Clearly dominant if top score is significantly higher (margin >= 0.20) or if candidate is within 1 mile
                if ($top['confidence'] >= ($second['confidence'] + 0.20) || ($top['distance_miles'] ?? 999) <= 1.0) {
                    $this->logger->info('POI candidate selected with strong confidence', [
                        'query' => $rawQuery,
                        'selected' => $top['place_name'],
                        'distance_miles' => $top['distance_miles'],
                        'score' => $top['confidence'],
                    ]);

                    return [
                        'status' => 'SELECTED',
                        'selected_feature' => $top['feature'],
                        'candidates' => $candidateSummaries,
                        'debug' => [
                            'original_query' => $rawQuery,
                            'is_generic_poi' => true,
                            'candidate_count' => count($features),
                            'selected_candidate' => $top['place_name'],
                            'candidate_coordinates' => $top['coordinates']->toArray(),
                            'candidate_distance_from_pickup' => $top['distance_miles'],
                            'confidence_score' => round($top['confidence'], 4),
                            'reason' => 'Top candidate matched POI identity and is geographically relevant to pickup.',
                        ],
                    ];
                }

                // Multiple candidates with similar confidence exist in metropolitan area
                $this->logger->info('POI disambiguation: Multiple viable nearby candidates found', [
                    'query' => $rawQuery,
                    'candidate_count' => count($scored),
                ]);

                return [
                    'status' => 'AMBIGUOUS_MULTIPLE_CANDIDATES',
                    'selected_feature' => null,
                    'candidates' => $candidateSummaries,
                    'debug' => [
                        'original_query' => $rawQuery,
                        'is_generic_poi' => true,
                        'candidate_count' => count($features),
                        'valid_candidates_count' => count($scored),
                        'selected_candidate' => null,
                        'confidence_score' => round($top['confidence'], 4),
                        'reason' => sprintf('Multiple (%d) candidate branches found within metropolitan radius. Explicit selection required.', count($scored)),
                    ],
                ];
            }

            // Single confident candidate
            return [
                'status' => 'SELECTED',
                'selected_feature' => $top['feature'],
                'candidates' => $candidateSummaries,
                'debug' => [
                    'original_query' => $rawQuery,
                    'is_generic_poi' => true,
                    'candidate_count' => count($features),
                    'selected_candidate' => $top['place_name'],
                    'candidate_coordinates' => $top['coordinates']->toArray(),
                    'candidate_distance_from_pickup' => $top['distance_miles'],
                    'confidence_score' => round($top['confidence'], 4),
                    'reason' => 'Single confident nearby candidate selected.',
                ],
            ];
        }

        // Case 3: Candidates exist but none reached confidence threshold
        return [
            'status' => 'AMBIGUOUS_NO_CANDIDATE',
            'selected_feature' => null,
            'candidates' => $candidateSummaries,
            'debug' => [
                'original_query' => $rawQuery,
                'is_generic_poi' => true,
                'candidate_count' => count($features),
                'valid_candidates_count' => count($scored),
                'top_score' => round($top['confidence'], 4),
                'confidence_threshold' => $confidenceThreshold,
                'reason' => 'Candidates found but none met the required confidence threshold.',
            ],
        ];
    }

    /**
     * Score an individual feature candidate.
     *
     * @param  array<string, mixed>  $feature
     * @return array{
     *     feature: array<string, mixed>,
     *     place_name: string,
     *     coordinates: Coordinates,
     *     distance_miles: float|null,
     *     name_match_score: float,
     *     distance_score: float,
     *     city_bonus: float,
     *     confidence: float
     * }
     */
    private function scoreFeature(
        array $feature,
        string $query,
        ?Coordinates $proximity,
        ?string $referenceCity,
        float $maxPoiRadiusMiles,
    ): array {
        $center = $feature['center'] ?? [0.0, 0.0];
        $coords = new Coordinates((float) ($center[1] ?? 0.0), (float) ($center[0] ?? 0.0));
        $placeName = (string) ($feature['place_name'] ?? '');
        $text = (string) ($feature['text'] ?? '');
        $featureId = (string) ($feature['id'] ?? '');

        // 1. Name Match Score (0.0 to 1.0)
        $queryLower = strtolower(trim($query));
        $placeLower = strtolower($placeName);
        $textLower = strtolower($text);

        // A feature whose id is locality or place and does not even contain the query is an unrelated locality
        if ((str_starts_with($featureId, 'locality') || str_starts_with($featureId, 'place')) &&
            ! str_contains($textLower, $queryLower) && ! str_contains($placeLower, $queryLower)) {
            $nameMatchScore = 0.0;
        } elseif (str_contains($placeLower, $queryLower) || str_contains($textLower, $queryLower)) {
            $nameMatchScore = 1.0;
        } else {
            // Check word overlap
            $queryTokens = array_filter(explode(' ', $queryLower), fn ($t) => strlen($t) > 2);
            $matchedTokens = 0;
            foreach ($queryTokens as $token) {
                if (str_contains($placeLower, $token) || str_contains($textLower, $token)) {
                    $matchedTokens++;
                }
            }

            if (! empty($queryTokens)) {
                $tokenRatio = $matchedTokens / count($queryTokens);
                // If only 1 out of multiple words matched, heavily downweight
                $nameMatchScore = $tokenRatio === 1.0 ? 0.8 : ($tokenRatio >= 0.5 ? 0.2 : 0.0);
            } else {
                $nameMatchScore = 0.0;
            }
        }

        // If name relevance is 0, this candidate is not the requested business
        if ($nameMatchScore === 0.0) {
            return [
                'feature' => $feature,
                'place_name' => $placeName,
                'coordinates' => $coords,
                'distance_miles' => null,
                'name_match_score' => 0.0,
                'distance_score' => 0.0,
                'city_bonus' => 0.0,
                'confidence' => 0.0,
            ];
        }

        // 2. Distance Score & Maximum Radius Constraint
        $distanceMiles = null;
        $distanceScore = 0.5; // Neutral default if proximity unknown

        if ($proximity !== null) {
            $distanceMiles = $proximity->distanceToInMiles($coords);

            // Rejection rule: If candidate exceeds maximum allowable radius for generic POIs
            if ($distanceMiles > $maxPoiRadiusMiles) {
                return [
                    'feature' => $feature,
                    'place_name' => $placeName,
                    'coordinates' => $coords,
                    'distance_miles' => $distanceMiles,
                    'name_match_score' => $nameMatchScore,
                    'distance_score' => 0.0,
                    'city_bonus' => 0.0,
                    'confidence' => 0.0, // Instantly rejected as out-of-bounds
                ];
            }

            // Distance score: closer candidates score higher
            $distanceScore = max(0.0, 1.0 - ($distanceMiles / $maxPoiRadiusMiles));
        }

        // 3. City / Metropolitan Bonus
        $cityBonus = 0.0;
        if ($referenceCity !== null && $this->cityMatcher->matchesCityContext($feature, $referenceCity)) {
            $cityBonus = 0.15;
        }

        // 4. Combined Confidence Score
        $confidence = ($nameMatchScore * 0.55) + ($distanceScore * 0.30) + $cityBonus;
        $confidence = min(1.0, max(0.0, $confidence));

        return [
            'feature' => $feature,
            'place_name' => $placeName,
            'coordinates' => $coords,
            'distance_miles' => $distanceMiles,
            'name_match_score' => $nameMatchScore,
            'distance_score' => $distanceScore,
            'city_bonus' => $cityBonus,
            'confidence' => $confidence,
        ];
    }
}
