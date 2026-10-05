<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Currency
    |--------------------------------------------------------------------------
    |
    | The default ISO currency code for taxi estimates. UK market default is GBP.
    |
    */
    'currency' => env('TAXISCANNER_CURRENCY', 'GBP'),

    /*
    |--------------------------------------------------------------------------
    | Taxi Providers Configuration
    |--------------------------------------------------------------------------
    |
    | Configure supported taxi providers. In the future, when official authorized
    | APIs become available, API keys and credentials can be supplied here without
    | changing core domain contracts.
    |
    */
    'providers' => [
        'uber' => [
            'enabled' => env('PROVIDER_UBER_ENABLED', true),
            'display_name' => 'Uber',
            'api_mode' => env('PROVIDER_UBER_MODE', 'estimate'), // 'estimate' or 'live'
            'api_client_id' => env('PROVIDER_UBER_CLIENT_ID', null),
            'api_client_secret' => env('PROVIDER_UBER_CLIENT_SECRET', null),
            'booking_url' => 'https://m.uber.com/ul/?action=setPickup',
        ],
        'bolt' => [
            'enabled' => env('PROVIDER_BOLT_ENABLED', true),
            'display_name' => 'Bolt',
            'api_mode' => env('PROVIDER_BOLT_MODE', 'estimate'),
            'api_key' => env('PROVIDER_BOLT_API_KEY', null),
            'booking_url' => 'https://bolt.eu',
        ],
        'streetcars' => [
            'enabled' => env('PROVIDER_STREETCARS_ENABLED', true),
            'display_name' => 'StreetCars',
            'api_mode' => env('PROVIDER_STREETCARS_MODE', 'estimate'),
            'api_key' => env('PROVIDER_STREETCARS_API_KEY', null),
            'booking_url' => 'https://www.streetcarsmanchester.co.uk',
        ],
        'veezu' => [
            'enabled' => env('PROVIDER_VEEZU_ENABLED', true),
            'display_name' => 'Veezu',
            'api_mode' => env('PROVIDER_VEEZU_MODE', 'estimate'),
            'api_key' => env('PROVIDER_VEEZU_API_KEY', null),
            'booking_url' => 'https://www.veezu.co.uk',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Caching Configuration
    |--------------------------------------------------------------------------
    |
    | Caching strategy for external lookups:
    | - Geocoding: Addresses/postcodes rarely change, so cache aggressively (14-30 days).
    | - Routes: Road distances/standard durations between fixed coordinates change infrequently (7 days).
    | - Estimates: Fares may vary with time-of-day/surge, so cache shortly (3-5 minutes) or recalculate dynamically.
    |
    */
    'caching' => [
        'prefix' => env('TAXISCANNER_CACHE_PREFIX', 'taxiscanner'),
        'geocoding_ttl' => (int) env('TAXISCANNER_GEOCODING_CACHE_TTL', 1209600), // 14 days
        'route_ttl' => (int) env('TAXISCANNER_ROUTE_CACHE_TTL', 604800),         // 7 days
        'estimate_ttl' => (int) env('TAXISCANNER_ESTIMATE_CACHE_TTL', 300),      // 5 minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | Location & Mapping Providers
    |--------------------------------------------------------------------------
    |
    | Supported providers: 'mapbox', 'simulated', 'google'
    |
    */
    'geocoding' => [
        'provider' => env('TAXISCANNER_GEOCODING_PROVIDER', 'mapbox'),
        'api_key' => env('TAXISCANNER_MAP_API_KEY', env('MAPBOX_ACCESS_TOKEN', null)),
        'timeout' => (int) env('TAXISCANNER_MAP_TIMEOUT', 5),
        'country' => env('TAXISCANNER_GEOCODING_COUNTRY', 'gb'),
        'allow_simulated_fallback' => (bool) env('TAXISCANNER_ALLOW_SIMULATED_FALLBACK', false),
        'poi_max_radius_miles' => (float) env('TAXISCANNER_POI_MAX_RADIUS_MILES', 35.0),
        'poi_confidence_threshold' => (float) env('TAXISCANNER_POI_CONFIDENCE_THRESHOLD', 0.60),
    ],

    'routing' => [
        'provider' => env('TAXISCANNER_ROUTING_PROVIDER', 'mapbox'),
        'api_key' => env('TAXISCANNER_MAP_API_KEY', env('MAPBOX_ACCESS_TOKEN', null)),
        'timeout' => (int) env('TAXISCANNER_MAP_TIMEOUT', 5),
        'allow_simulated_fallback' => (bool) env('TAXISCANNER_ALLOW_SIMULATED_FALLBACK', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Analytics & Search Persistence
    |--------------------------------------------------------------------------
    |
    | Whether to store route searches and generated fare estimates in PostgreSQL.
    |
    */
    'analytics' => [
        'enabled' => (bool) env('TAXISCANNER_ANALYTICS_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | StreetCars Empirical Geographic Calibration (Airport to Suburb)
    |--------------------------------------------------------------------------
    |
    | Experimental out-of-sample empirical geographic calibration model for
    | StreetCars airport_to_suburb trips. Disabled by default in production.
    | These are TaxiScanner empirical calibration zones derived from user-confirmed
    | observations, NOT official StreetCars tariff zones.
    |
    */
    'streetcars' => [
        'geographic_calibration' => [
            'enabled' => (bool) env('TAXISCANNER_STREETCARS_GEO_CALIBRATION_ENABLED', false),
            'confidence_threshold' => 0.60,
            'zones' => [
                'handforth_wilmslow' => [
                    'name' => 'Handforth & Wilmslow',
                    'priority' => 80,
                    'multiplier' => 1.1721,
                    'minimum_floor' => 22.00,
                    'fixed_fare' => null,
                    'keywords' => ['handforth', 'wilmslow'],
                    'negative_keywords' => ['didsbury', 'withington', 'fallowfield', 'rusholme'],
                    'postcode_prefixes' => ['SK9'],
                    'center' => ['latitude' => 53.3350, 'longitude' => -2.2280],
                    'radius_miles' => 3.2,
                ],
                'trafford_north' => [
                    'name' => 'Trafford North (Urmston / Stretford)',
                    'priority' => 70,
                    'multiplier' => 1.2734,
                    'minimum_floor' => null,
                    'fixed_fare' => null,
                    'keywords' => ['urmston', 'stretford', 'flixton', 'davyhulme', 'trafford park'],
                    'postcode_prefixes' => ['M41', 'M32'],
                    'center' => ['latitude' => 53.4480, 'longitude' => -2.3350],
                    'radius_miles' => 3.5,
                ],
                'altrincham' => [
                    'name' => 'Altrincham & Surrounds',
                    'priority' => 65,
                    'multiplier' => 1.0945,
                    'minimum_floor' => null,
                    'fixed_fare' => null,
                    'keywords' => ['altrincham', 'timperley', 'hale', 'bowdon', 'broadheath'],
                    'postcode_prefixes' => ['WA14', 'WA15'],
                    'center' => ['latitude' => 53.3870, 'longitude' => -2.3550],
                    'radius_miles' => 3.0,
                ],
                'sale' => [
                    'name' => 'Sale & Surrounds',
                    'priority' => 65,
                    'multiplier' => 1.0945,
                    'minimum_floor' => null,
                    'fixed_fare' => null,
                    'keywords' => ['sale', 'brooklands', 'ashton on mersey'],
                    'postcode_prefixes' => ['M33'],
                    'center' => ['latitude' => 53.4240, 'longitude' => -2.3240],
                    'radius_miles' => 2.5,
                ],
                'stockport' => [
                    'name' => 'Stockport Town Centre & Surrounds',
                    'priority' => 60,
                    'multiplier' => 1.2580,
                    'minimum_floor' => null,
                    'fixed_fare' => null,
                    'keywords' => ['stockport', 'stockport town centre', 'merseyway', 'heaton norris', 'heaton chapel', 'edgeley'],
                    'negative_keywords' => ['bramhall', 'poynton', 'hazel grove', 'marple', 'romiley', 'woodley', 'bredbury'],
                    'postcode_prefixes' => ['SK1', 'SK2', 'SK3', 'SK4', 'SK5'],
                    'center' => ['latitude' => 53.4110, 'longitude' => -2.1600],
                    'radius_miles' => 3.0,
                ],
                'didsbury' => [
                    'name' => 'Didsbury & Surrounds',
                    'priority' => 65,
                    'multiplier' => 1.1518,
                    'minimum_floor' => null,
                    'fixed_fare' => null,
                    'keywords' => ['didsbury', 'didsbury village', 'east didsbury', 'west didsbury'],
                    'negative_keywords' => ['cheadle', 'gatley', 'heald green', 'withington', 'fallowfield', 'rusholme', 'burnage', 'chorlton'],
                    'postcode_prefixes' => [],
                    'center' => ['latitude' => 53.4170, 'longitude' => -2.2315],
                    'radius_miles' => 2.5,
                ],
                'cheadle' => [
                    'name' => 'Cheadle Corridor',
                    'priority' => 75,
                    'multiplier' => 0.8698,
                    'minimum_floor' => null,
                    'fixed_fare' => null,
                    'keywords' => ['cheadle', 'cheadle hulme', 'gatley', 'heald green'],
                    'postcode_prefixes' => ['SK8'],
                    'center' => ['latitude' => 53.3930, 'longitude' => -2.2140],
                    'radius_miles' => 3.0,
                ],
                'knutsford' => [
                    'name' => 'Knutsford & Surrounds',
                    'priority' => 70,
                    'multiplier' => 0.8758,
                    'minimum_floor' => null,
                    'fixed_fare' => null,
                    'keywords' => ['knutsford', 'tatton', 'mobberley'],
                    'postcode_prefixes' => ['WA16'],
                    'center' => ['latitude' => 53.3040, 'longitude' => -2.3750],
                    'radius_miles' => 4.0,
                ],
                'macclesfield' => [
                    'name' => 'Macclesfield & Surrounds',
                    'priority' => 70,
                    'multiplier' => 0.9424,
                    'minimum_floor' => null,
                    'fixed_fare' => null,
                    'keywords' => ['macclesfield', 'bollington', 'prestbury', 'tytherington'],
                    'postcode_prefixes' => ['SK10', 'SK11'],
                    'center' => ['latitude' => 53.2580, 'longitude' => -2.1260],
                    'radius_miles' => 4.5,
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Suburban Transport Hubs (Inter-Suburb Orbital Trip Detection)
    |--------------------------------------------------------------------------
    |
    | Recognised suburban areas and transport hubs outside Manchester city centre.
    | Journeys between distinct outer hubs (e.g. Stockport to Trafford Park)
    | are classified as inter_suburb to distinguish orbital trips from city-centre radial trips.
    |
    */
    'suburban_hubs' => [
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
    ],
];
