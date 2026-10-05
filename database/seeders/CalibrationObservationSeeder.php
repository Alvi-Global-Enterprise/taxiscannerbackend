<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Taxi\Models\CalibrationObservation;
use App\Domain\Taxi\Models\Provider;
use Illuminate\Database\Seeder;

class CalibrationObservationSeeder extends Seeder
{
    public function run(): void
    {
        $provider = Provider::where('slug', 'streetcars')->first();

        if (! $provider) {
            return;
        }

        // Observation 1: Manchester Piccadilly Station → Manchester Arndale Centre
        // Category: city_short (0.81 miles)
        CalibrationObservation::updateOrCreate(
            [
                'provider_slug' => 'streetcars',
                'pickup' => 'Manchester Piccadilly Station',
                'dropoff' => 'Manchester Arndale Centre',
                'observed_real_world_fare' => 3.75,
            ],
            [
                'provider_id' => $provider->id,
                'route_distance' => 0.81,
                'route_duration' => 6,
                'estimated_fare' => 5.44,
                'difference_percentage' => -31.07,
                'recommended_multiplier' => 0.6893,
                'observed_at' => now(),
                'trip_category' => 'city_short',
                'notes' => 'Confirmed StreetCars observation: Piccadilly to Arndale Centre. Real price: £3.00-£4.50 (mid £3.75). TaxiScanner StreetCars: £5.17-£5.71 (mid £5.44). Ratio: 0.6893.',
                'is_outlier' => false,
                'metadata' => [
                    'source' => 'real_streetcars_observation',
                    'real_price_range' => ['min' => 3.00, 'max' => 4.50, 'midpoint' => 3.75],
                    'taxiscanner_estimate_range' => ['min' => 5.17, 'max' => 5.71, 'midpoint' => 5.44],
                ],
            ]
        );

        // Observation 2: Manchester Piccadilly Station → Old Trafford
        // Category: city_short (3.48 miles)
        CalibrationObservation::updateOrCreate(
            [
                'provider_slug' => 'streetcars',
                'pickup' => 'Manchester Piccadilly Station',
                'dropoff' => 'Old Trafford',
                'observed_real_world_fare' => 6.00,
            ],
            [
                'provider_id' => $provider->id,
                'route_distance' => 3.48,
                'route_duration' => 14,
                'estimated_fare' => 8.87,
                'difference_percentage' => -32.36,
                'recommended_multiplier' => 0.6764,
                'observed_at' => now(),
                'trip_category' => 'city_short',
                'notes' => 'Confirmed StreetCars observation: Piccadilly to Old Trafford. Real price: £5.00-£7.00 (mid £6.00). TaxiScanner StreetCars: £8.43-£9.31 (mid £8.87). Ratio: 0.6764.',
                'is_outlier' => false,
                'metadata' => [
                    'source' => 'real_streetcars_observation',
                    'real_price_range' => ['min' => 5.00, 'max' => 7.00, 'midpoint' => 6.00],
                    'taxiscanner_estimate_range' => ['min' => 8.43, 'max' => 9.31, 'midpoint' => 8.87],
                ],
            ]
        );

        // Observation 3: Manchester Airport → Manchester Piccadilly Station
        // Category: airport_to_city (9.44 miles)
        CalibrationObservation::updateOrCreate(
            [
                'provider_slug' => 'streetcars',
                'pickup' => 'Manchester Airport (MAN)',
                'dropoff' => 'Manchester Piccadilly Station',
                'observed_real_world_fare' => 26.00,
            ],
            [
                'provider_id' => $provider->id,
                'route_distance' => 9.44,
                'route_duration' => 27,
                'estimated_fare' => 24.905,
                'difference_percentage' => 4.40,
                'recommended_multiplier' => 1.0440,
                'observed_at' => now(),
                'trip_category' => 'airport_to_city',
                'notes' => 'Confirmed StreetCars observation: Airport to Piccadilly Station. Real price: £26.00. TaxiScanner StreetCars: £23.66-£26.15 (mid £24.905). Ratio: 1.0440.',
                'is_outlier' => false,
                'metadata' => [
                    'source' => 'real_streetcars_observation',
                    'real_price' => 26.00,
                    'taxiscanner_estimate_range' => ['min' => 23.66, 'max' => 26.15, 'midpoint' => 24.905],
                ],
            ]
        );

        // Observation 4: Manchester Piccadilly Station → Manchester Airport
        // Category: city_to_airport (9.44 miles)
        CalibrationObservation::updateOrCreate(
            [
                'provider_slug' => 'streetcars',
                'pickup' => 'Manchester Piccadilly Station',
                'dropoff' => 'Manchester Airport (MAN)',
                'observed_real_world_fare' => 26.00,
            ],
            [
                'provider_id' => $provider->id,
                'route_distance' => 9.44,
                'route_duration' => 27,
                'estimated_fare' => 23.82,
                'difference_percentage' => 9.15,
                'recommended_multiplier' => 1.0915,
                'observed_at' => now(),
                'trip_category' => 'city_to_airport',
                'notes' => 'Confirmed StreetCars observation: Piccadilly Station to Manchester Airport. Real price: £26.00. TaxiScanner StreetCars: £22.63-£25.01 (mid £23.82). Ratio: 1.0915.',
                'is_outlier' => false,
                'metadata' => [
                    'source' => 'real_streetcars_observation',
                    'real_price' => 26.00,
                    'taxiscanner_estimate_range' => ['min' => 22.63, 'max' => 25.01, 'midpoint' => 23.82],
                ],
            ]
        );

        // Observation 5: Manchester Airport → Handforth / Vision Express
        // Category: airport_to_suburb (4.81 miles)
        CalibrationObservation::updateOrCreate(
            [
                'provider_slug' => 'streetcars',
                'pickup' => 'Manchester Airport (MAN), Select terminal pickup point',
                'dropoff' => 'Vision Express Opticians at Tesco - Handforth, Kiln Croft Lane, Handforth, Wilmslow, UK',
                'observed_real_world_fare' => 22.00,
            ],
            [
                'provider_id' => $provider->id,
                'route_distance' => 4.81,
                'route_duration' => 12,
                'estimated_fare' => 15.40,
                'difference_percentage' => 42.86,
                'recommended_multiplier' => 1.4286,
                'observed_at' => now(),
                'trip_category' => 'airport_to_suburb',
                'notes' => 'Confirmed StreetCars observation: Manchester Airport (MAN) to Vision Express Handforth. Real price: £22.00. TaxiScanner midpoint: £15.40. Ratio: 1.4286.',
                'is_outlier' => false,
                'metadata' => [
                    'source' => 'user_confirmed_booking_price',
                    'route_summary' => 'Via A555, A34',
                    'taxiscanner_estimate_range' => ['min' => 14.63, 'max' => 16.17, 'midpoint' => 15.40],
                ],
            ]
        );

        // Observation 6: Manchester Airport → Manchester City Centre / Portland Street
        // Category: airport_to_city (9.44 miles)
        CalibrationObservation::updateOrCreate(
            [
                'provider_slug' => 'streetcars',
                'pickup' => 'Manchester Airport (MAN)',
                'dropoff' => 'Manchester City Centre (Portland Street)',
                'observed_real_world_fare' => 26.00,
            ],
            [
                'provider_id' => $provider->id,
                'route_distance' => 9.44,
                'route_duration' => 27,
                'estimated_fare' => 24.30,
                'difference_percentage' => 7.00,
                'recommended_multiplier' => 1.0700,
                'observed_at' => now(),
                'trip_category' => 'airport_to_city',
                'notes' => 'Confirmed StreetCars observation: Manchester Airport (MAN) to Manchester City Centre / Portland Street. Real price: £26.00. TaxiScanner calculated midpoint: £24.30. Ratio: 1.0700.',
                'is_outlier' => false,
                'metadata' => [
                    'source' => 'user_confirmed_screenshot',
                    'route_summary' => 'Via M56, A5103',
                    'taxiscanner_estimate_range' => ['min' => 23.09, 'max' => 25.52, 'midpoint' => 24.30],
                ],
            ]
        );

        // Observation 7: Manchester Piccadilly Station → Salford Quays
        // Category: city_medium (4.01 miles)
        CalibrationObservation::updateOrCreate(
            [
                'provider_slug' => 'streetcars',
                'pickup' => 'Manchester Piccadilly Station, Manchester, M60 7RA',
                'dropoff' => 'Salford Quays, Salford, Manchester',
                'observed_real_world_fare' => 7.75,
            ],
            [
                'provider_id' => $provider->id,
                'route_distance' => 4.01,
                'route_duration' => 17,
                'estimated_fare' => 11.12,
                'difference_percentage' => -30.31,
                'recommended_multiplier' => 0.6971,
                'observed_at' => now(),
                'trip_category' => 'city_medium',
                'notes' => 'User-confirmed StreetCars booking/price observation: Piccadilly Station to Salford Quays. Real price range: £6.50-£9.00 (midpoint £7.75). TaxiScanner StreetCars midpoint: £11.12. Recommended multiplier: 0.6971.',
                'is_outlier' => false,
                'metadata' => [
                    'source' => 'user_confirmed_booking_price',
                    'real_price_range' => ['min' => 6.50, 'max' => 9.00, 'midpoint' => 7.75],
                    'taxiscanner_estimate_midpoint' => 11.12,
                ],
            ]
        );

        // Observation 8: Manchester Piccadilly Station → Trafford Centre
        // Category: city_medium (9.38 miles)
        CalibrationObservation::updateOrCreate(
            [
                'provider_slug' => 'streetcars',
                'pickup' => 'Manchester Piccadilly Station, Manchester, M60 7RA',
                'dropoff' => 'Trafford Centre, Select pickup point',
                'observed_real_world_fare' => 13.00,
            ],
            [
                'provider_id' => $provider->id,
                'route_distance' => 9.38,
                'route_duration' => 22,
                'estimated_fare' => 20.21,
                'difference_percentage' => -35.68,
                'recommended_multiplier' => 0.6432,
                'observed_at' => now(),
                'trip_category' => 'city_medium',
                'notes' => 'User-confirmed StreetCars booking/price observation: Piccadilly Station to Trafford Centre. Real price range: £11.00-£15.00 (midpoint £13.00). TaxiScanner StreetCars midpoint: £20.21. Recommended multiplier: 0.6432.',
                'is_outlier' => false,
                'metadata' => [
                    'source' => 'user_confirmed_booking_price',
                    'real_price_range' => ['min' => 11.00, 'max' => 15.00, 'midpoint' => 13.00],
                    'taxiscanner_estimate_midpoint' => 20.21,
                ],
            ]
        );

        // Observation 9: Manchester Piccadilly Station → MediaCityUK
        // Category: city_medium (4.20 miles)
        CalibrationObservation::updateOrCreate(
            [
                'provider_slug' => 'streetcars',
                'pickup' => 'Manchester Piccadilly Station, Manchester, M60 7RA, Manchester, M60 7RA, Meet driver at pickup point',
                'dropoff' => 'MediaCityUK, MediaCity UK, Salford, Manchester, UK',
                'observed_real_world_fare' => 8.50,
            ],
            [
                'provider_id' => $provider->id,
                'route_distance' => 4.20,
                'route_duration' => 17,
                'estimated_fare' => 11.42,
                'difference_percentage' => -25.57,
                'recommended_multiplier' => 0.7443,
                'observed_at' => now(),
                'trip_category' => 'city_medium',
                'notes' => 'User-confirmed StreetCars booking/price observation: Piccadilly Station to MediaCityUK. Real price range: £7.00-£10.00 (midpoint £8.50). TaxiScanner StreetCars midpoint: £11.42. Recommended multiplier: 0.7443.',
                'is_outlier' => false,
                'metadata' => [
                    'source' => 'user_confirmed_booking_price',
                    'real_price_range' => ['min' => 7.00, 'max' => 10.00, 'midpoint' => 8.50],
                    'taxiscanner_estimate_range' => ['min' => 10.85, 'max' => 11.99, 'midpoint' => 11.42],
                    'taxiscanner_estimate_midpoint' => 11.42,
                ],
            ]
        );

        // Observation 10: Manchester Airport → Stockport Town Centre
        // Category: airport_to_suburb (6.52 miles)
        CalibrationObservation::updateOrCreate(
            [
                'provider_slug' => 'streetcars',
                'pickup' => 'Manchester Airport (MAN), Select terminal pickup point',
                'dropoff' => 'Stockport Town Centre, Stockport, UK',
                'observed_real_world_fare' => 22.00,
            ],
            [
                'provider_id' => $provider->id,
                'route_distance' => 6.52,
                'route_duration' => 11,
                'estimated_fare' => 18.03,
                'difference_percentage' => 22.02,
                'recommended_multiplier' => 1.2202,
                'observed_at' => now(),
                'trip_category' => 'airport_to_suburb',
                'notes' => 'Confirmed StreetCars observation: Manchester Airport (MAN) to Stockport Town Centre. Real price: £22.00. TaxiScanner StreetCars estimate: £17.13-£18.93 (midpoint £18.03). Recommended multiplier: 1.2202.',
                'is_outlier' => false,
                'metadata' => [
                    'source' => 'user_confirmed_booking_price',
                    'real_price' => 22.00,
                    'taxiscanner_estimate_range' => ['min' => 17.13, 'max' => 18.93, 'midpoint' => 18.03],
                    'taxiscanner_estimate_midpoint' => 18.03,
                ],
            ]
        );

        // Observation 11: Manchester Airport → Cheadle, Greater Manchester
        // Category: airport_to_suburb (6.63 miles)
        CalibrationObservation::updateOrCreate(
            [
                'provider_slug' => 'streetcars',
                'pickup' => 'Manchester Airport (MAN), Select terminal pickup point',
                'dropoff' => 'Cheadle, Greater Manchester, UK',
                'observed_real_world_fare' => 16.80,
            ],
            [
                'provider_id' => $provider->id,
                'route_distance' => 6.63,
                'route_duration' => 14,
                'estimated_fare' => 18.51,
                'difference_percentage' => -9.24,
                'recommended_multiplier' => 0.9076,
                'observed_at' => now(),
                'trip_category' => 'airport_to_suburb',
                'notes' => 'Confirmed StreetCars observation: Manchester Airport (MAN) to Cheadle. Real price: £16.80. TaxiScanner StreetCars estimate: £17.58-£19.44 (midpoint £18.51). Recommended multiplier: 0.9076.',
                'is_outlier' => false,
                'metadata' => [
                    'source' => 'user_confirmed_booking_price',
                    'route_summary' => 'Via M56, A34',
                    'is_estimated' => false,
                    'real_price' => 16.80,
                    'taxiscanner_estimate_range' => ['min' => 17.58, 'max' => 19.44, 'midpoint' => 18.51],
                    'taxiscanner_estimate_midpoint' => 18.51,
                ],
            ]
        );

        // Observation 12: Manchester Piccadilly Station → Northern Quarter
        // Category: city_short (0.44 miles)
        CalibrationObservation::updateOrCreate(
            [
                'provider_slug' => 'streetcars',
                'pickup' => 'Manchester Piccadilly Station, Manchester, M60 7RA, Meet driver at pickup point',
                'dropoff' => 'Northern Quarter, Manchester, UK',
                'observed_real_world_fare' => 4.25,
            ],
            [
                'provider_id' => $provider->id,
                'route_distance' => 0.44,
                'route_duration' => 3,
                'estimated_fare' => 5.125,
                'difference_percentage' => -17.07,
                'recommended_multiplier' => 0.8293,
                'observed_at' => now(),
                'trip_category' => 'city_short',
                'notes' => 'Confirmed StreetCars observation: Piccadilly Station to Northern Quarter. Real price: £3.50-£5.00 (midpoint £4.25). TaxiScanner StreetCars estimate: £5.00-£5.25 (midpoint £5.125). Recommended multiplier: 0.8293.',
                'is_outlier' => false,
                'metadata' => [
                    'source' => 'user_confirmed_booking_price',
                    'route_summary' => 'Via Newton Street, Spear Street',
                    'is_estimated' => false,
                    'real_price_range' => ['min' => 3.50, 'max' => 5.00, 'midpoint' => 4.25],
                    'taxiscanner_estimate_range' => ['min' => 5.00, 'max' => 5.25, 'midpoint' => 5.125],
                    'taxiscanner_estimate_midpoint' => 5.125,
                ],
            ]
        );

        // Observation 13: Manchester Airport → Oxford Road
        // Category: airport_to_city (8.93 miles)
        CalibrationObservation::updateOrCreate(
            [
                'provider_slug' => 'streetcars',
                'pickup' => 'Manchester Airport (MAN)',
                'dropoff' => 'Oxford Road, Manchester, Manchester, UK',
                'observed_real_world_fare' => 24.00,
            ],
            [
                'provider_id' => $provider->id,
                'route_distance' => 8.93,
                'route_duration' => 24,
                'estimated_fare' => 23.19,
                'difference_percentage' => 3.49,
                'recommended_multiplier' => 1.0349,
                'observed_at' => now(),
                'trip_category' => 'airport_to_city',
                'notes' => 'Confirmed StreetCars observation: Manchester Airport (MAN) to Oxford Road. Real price: £24.00. TaxiScanner StreetCars estimate: £22.03-£24.35 (midpoint £23.19). Recommended multiplier: 1.0349.',
                'is_outlier' => false,
                'metadata' => [
                    'source' => 'user_confirmed_booking_price',
                    'route_summary' => 'Via M56, A5103',
                    'is_estimated' => false,
                    'real_price' => 24.00,
                    'taxiscanner_estimate_range' => ['min' => 22.03, 'max' => 24.35, 'midpoint' => 23.19],
                    'taxiscanner_estimate_midpoint' => 23.19,
                ],
            ]
        );

        // Observation 14: Oxford Road Station → Manchester Airport
        // Category: city_to_airport (8.56 miles)
        CalibrationObservation::updateOrCreate(
            [
                'provider_slug' => 'streetcars',
                'pickup' => 'Oxford Road Station, Manchester, Manchester, UK',
                'dropoff' => 'Manchester Airport (MAN)',
                'observed_real_world_fare' => 26.00,
            ],
            [
                'provider_id' => $provider->id,
                'route_distance' => 8.56,
                'route_duration' => 23,
                'estimated_fare' => 22.505,
                'difference_percentage' => 15.53,
                'recommended_multiplier' => 1.1553,
                'observed_at' => now(),
                'trip_category' => 'city_to_airport',
                'notes' => 'Confirmed StreetCars observation: Oxford Road Station to Manchester Airport (MAN). Real price: £26.00. TaxiScanner StreetCars estimate: £21.38-£23.63 (midpoint £22.505). Recommended multiplier: 1.1553.',
                'is_outlier' => false,
                'metadata' => [
                    'source' => 'user_confirmed_booking_price',
                    'route_summary' => 'Via A5103, M56',
                    'is_estimated' => false,
                    'real_price' => 26.00,
                    'taxiscanner_estimate_range' => ['min' => 21.38, 'max' => 23.63, 'midpoint' => 22.505],
                    'taxiscanner_estimate_midpoint' => 22.505,
                ],
            ]
        );

        // Observation 15: Salford Quays → Manchester Airport
        // Category: city_to_airport (10.50 miles)
        CalibrationObservation::updateOrCreate(
            [
                'provider_slug' => 'streetcars',
                'pickup' => 'Salford Quays, Salford, Manchester, UK',
                'dropoff' => 'Manchester Airport (MAN)',
                'observed_real_world_fare' => 28.00,
            ],
            [
                'provider_id' => $provider->id,
                'route_distance' => 10.50,
                'route_duration' => 26,
                'estimated_fare' => 25.905,
                'difference_percentage' => 8.09,
                'recommended_multiplier' => 1.0809,
                'observed_at' => now(),
                'trip_category' => 'city_to_airport',
                'notes' => 'Confirmed StreetCars observation: Salford Quays to Manchester Airport (MAN). Real price: £28.00. TaxiScanner StreetCars estimate: £24.61-£27.20 (midpoint £25.905). Recommended multiplier: 1.0809.',
                'is_outlier' => false,
                'metadata' => [
                    'source' => 'user_confirmed_booking_price',
                    'route_summary' => 'Via M60, M56',
                    'is_estimated' => false,
                    'real_price' => 28.00,
                    'taxiscanner_estimate_range' => ['min' => 24.61, 'max' => 27.20, 'midpoint' => 25.905],
                    'taxiscanner_estimate_midpoint' => 25.905,
                ],
            ]
        );

        // Observation 16: Route A - Manchester Piccadilly Station → Manchester Airport
        // Category: city_to_airport (9.41 miles) - OUT-OF-SAMPLE VALIDATION
        CalibrationObservation::updateOrCreate(
            [
                'provider_slug' => 'streetcars',
                'pickup' => 'Manchester Piccadilly Station, Manchester, M60 7RA, Meet driver at pickup point',
                'dropoff' => 'Manchester Airport (MAN), Select terminal pickup point',
                'observed_real_world_fare' => 26.00,
            ],
            [
                'provider_id' => $provider->id,
                'route_distance' => 9.41,
                'route_duration' => 27,
                'estimated_fare' => 26.48,
                'difference_percentage' => -1.81,
                'recommended_multiplier' => 0.9819,
                'observed_at' => now(),
                'trip_category' => 'city_to_airport',
                'notes' => 'Out-of-sample validation observation: Piccadilly Station to Manchester Airport (MAN). Real price: £26.00. TaxiScanner midpoint used for comparison: £26.48 (applied multiplier 1.0915; uncalibrated baseline: £24.26). Recommended multiplier: 0.9819.',
                'is_outlier' => false,
                'metadata' => [
                    'source' => 'user_confirmed_booking_price',
                    'validation_type' => 'out_of_sample',
                    'real_price' => 26.00,
                    'taxiscanner_midpoint_used' => 26.48,
                    'applied_multiplier' => 1.0915,
                    'uncalibrated_base_fare' => 24.26,
                    'taxiscanner_estimate_range' => ['min' => 25.16, 'max' => 27.80, 'midpoint' => 26.48],
                    'taxiscanner_estimate_midpoint' => 26.48,
                ],
            ]
        );

        // Observation 17: Route B - Manchester Piccadilly Station → Deansgate
        // Category: city_short (1.24 miles) - OUT-OF-SAMPLE VALIDATION
        CalibrationObservation::updateOrCreate(
            [
                'provider_slug' => 'streetcars',
                'pickup' => 'Manchester Piccadilly Station, Manchester, M60 7RA, Meet driver at pickup point',
                'dropoff' => 'Deansgate, Manchester, UK',
                'observed_real_world_fare' => 3.75,
            ],
            [
                'provider_id' => $provider->id,
                'route_distance' => 1.24,
                'route_duration' => 10,
                'estimated_fare' => 5.125,
                'difference_percentage' => -26.83,
                'recommended_multiplier' => 0.7317,
                'observed_at' => now(),
                'trip_category' => 'city_short',
                'notes' => 'Out-of-sample validation observation: Piccadilly Station to Deansgate. Real price range: £3.00-£4.50 (midpoint £3.75). TaxiScanner midpoint used for comparison: £5.125 (applied multiplier 0.6893). Recommended multiplier: 0.7317.',
                'is_outlier' => false,
                'metadata' => [
                    'source' => 'user_confirmed_booking_price',
                    'validation_type' => 'out_of_sample',
                    'real_price_range' => ['min' => 3.00, 'max' => 4.50, 'midpoint' => 3.75],
                    'taxiscanner_midpoint_used' => 5.125,
                    'applied_multiplier' => 0.6893,
                    'taxiscanner_estimate_range' => ['min' => 5.00, 'max' => 5.25, 'midpoint' => 5.125],
                    'taxiscanner_estimate_midpoint' => 5.125,
                ],
            ]
        );

        // Observation 18: Route E - Manchester Airport → Wilmslow
        // Category: airport_to_suburb (6.73 miles) - OUT-OF-SAMPLE VALIDATION
        CalibrationObservation::updateOrCreate(
            [
                'provider_slug' => 'streetcars',
                'pickup' => 'Manchester Airport (MAN), Select terminal pickup point',
                'dropoff' => 'Wilmslow, Wilmslow, UK',
                'observed_real_world_fare' => 22.00,
            ],
            [
                'provider_id' => $provider->id,
                'route_distance' => 6.73,
                'route_duration' => 15,
                'estimated_fare' => 18.77,
                'difference_percentage' => 17.21,
                'recommended_multiplier' => 1.1721,
                'observed_at' => now(),
                'trip_category' => 'airport_to_suburb',
                'notes' => 'Out-of-sample validation observation: Manchester Airport (MAN) to Wilmslow. Real price: £22.00. TaxiScanner midpoint used for comparison: £18.77 (applied multiplier 1.0000 fallback). Recommended multiplier: 1.1721.',
                'is_outlier' => false,
                'metadata' => [
                    'source' => 'user_confirmed_booking_price',
                    'validation_type' => 'out_of_sample',
                    'real_price' => 22.00,
                    'taxiscanner_midpoint_used' => 18.77,
                    'applied_multiplier' => 1.0000,
                    'taxiscanner_estimate_range' => ['min' => 17.83, 'max' => 19.71, 'midpoint' => 18.77],
                    'taxiscanner_estimate_midpoint' => 18.77,
                ],
            ]
        );

        // Observation 19: Route F - Manchester Airport → Altrincham Town Centre Partnership
        // Category: airport_to_suburb (5.66 miles) - OUT-OF-SAMPLE VALIDATION
        CalibrationObservation::updateOrCreate(
            [
                'provider_slug' => 'streetcars',
                'pickup' => 'Manchester Airport (MAN), Select terminal pickup point',
                'dropoff' => 'Altrincham Town Centre Partnership, Greenwood Street, Altrincham, UK',
                'observed_real_world_fare' => 19.00,
            ],
            [
                'provider_id' => $provider->id,
                'route_distance' => 5.66,
                'route_duration' => 18,
                'estimated_fare' => 17.36,
                'difference_percentage' => 9.45,
                'recommended_multiplier' => 1.0945,
                'observed_at' => now(),
                'trip_category' => 'airport_to_suburb',
                'notes' => 'Out-of-sample validation observation: Manchester Airport (MAN) to Altrincham Town Centre Partnership. Real price: £19.00. TaxiScanner midpoint used for comparison: £17.36 (applied multiplier 1.0000 fallback). Recommended multiplier: 1.0945.',
                'is_outlier' => false,
                'metadata' => [
                    'source' => 'user_confirmed_booking_price',
                    'validation_type' => 'out_of_sample',
                    'real_price' => 19.00,
                    'taxiscanner_midpoint_used' => 17.36,
                    'applied_multiplier' => 1.0000,
                    'taxiscanner_estimate_range' => ['min' => 16.49, 'max' => 18.23, 'midpoint' => 17.36],
                    'taxiscanner_estimate_midpoint' => 17.36,
                ],
            ]
        );

        // Observation 20: Route G - Deansgate → Manchester Airport
        // Category: city_to_airport (9.01 miles) - OUT-OF-SAMPLE VALIDATION
        CalibrationObservation::updateOrCreate(
            [
                'provider_slug' => 'streetcars',
                'pickup' => 'Deansgate, Manchester, UK',
                'dropoff' => 'Manchester Airport (MAN), Select terminal pickup point',
                'observed_real_world_fare' => 26.00,
            ],
            [
                'provider_id' => $provider->id,
                'route_distance' => 9.01,
                'route_duration' => 26,
                'estimated_fare' => 25.67,
                'difference_percentage' => 1.29,
                'recommended_multiplier' => 1.0129,
                'observed_at' => now(),
                'trip_category' => 'city_to_airport',
                'notes' => 'Out-of-sample validation observation: Deansgate to Manchester Airport (MAN). Real price: £26.00. TaxiScanner midpoint used for comparison: £25.67 (applied multiplier 1.0915; uncalibrated baseline: £23.52). Recommended multiplier: 1.0129.',
                'is_outlier' => false,
                'metadata' => [
                    'source' => 'user_confirmed_booking_price',
                    'validation_type' => 'out_of_sample',
                    'real_price' => 26.00,
                    'taxiscanner_midpoint_used' => 25.67,
                    'applied_multiplier' => 1.0915,
                    'uncalibrated_base_fare' => 23.52,
                    'taxiscanner_estimate_range' => ['min' => 24.39, 'max' => 26.95, 'midpoint' => 25.67],
                    'taxiscanner_estimate_midpoint' => 25.67,
                ],
            ]
        );

        // Observation 21: Route 1 - Manchester Airport → Cheadle (Batch 2)
        // Category: airport_to_suburb (6.63 miles) - OUT-OF-SAMPLE VALIDATION
        CalibrationObservation::updateOrCreate(
            [
                'provider_slug' => 'streetcars',
                'pickup' => 'Manchester Airport (MAN), Select terminal pickup point',
                'dropoff' => 'Cheadle, Greater Manchester, England, United Kingdom',
                'observed_real_world_fare' => 15.40,
            ],
            [
                'provider_id' => $provider->id,
                'route_distance' => 6.63,
                'route_duration' => 14,
                'estimated_fare' => 18.51,
                'difference_percentage' => -16.80,
                'recommended_multiplier' => 0.8320,
                'observed_at' => now(),
                'trip_category' => 'airport_to_suburb',
                'notes' => 'Out-of-sample validation observation (Batch 2): Airport to Cheadle. Real price: £15.40. TaxiScanner midpoint used for comparison: £18.51 (applied multiplier 1.0000 fallback). Recommended multiplier: 0.8320.',
                'is_outlier' => false,
                'metadata' => [
                    'source' => 'user_confirmed_booking_price',
                    'validation_type' => 'out_of_sample',
                    'validation_batch' => 2,
                    'real_price' => 15.40,
                    'taxiscanner_midpoint_used' => 18.51,
                    'applied_multiplier' => 1.0000,
                    'taxiscanner_estimate_range' => ['min' => 17.58, 'max' => 19.44, 'midpoint' => 18.51],
                    'taxiscanner_estimate_midpoint' => 18.51,
                ],
            ]
        );

        // Observation 22: Route 2 - Manchester Airport → Wilmslow (Batch 2)
        // Category: airport_to_suburb (6.73 miles) - OUT-OF-SAMPLE VALIDATION
        CalibrationObservation::updateOrCreate(
            [
                'provider_slug' => 'streetcars',
                'pickup' => 'Manchester Airport (MAN), Select terminal pickup point',
                'dropoff' => 'Wilmslow, Wilmslow, UK',
                'notes' => 'Out-of-sample validation observation (Batch 2): Airport to Wilmslow. Real price: £22.00. TaxiScanner midpoint used for comparison: £18.77 (applied multiplier 1.0000 fallback). Recommended multiplier: 1.1721.',
            ],
            [
                'provider_id' => $provider->id,
                'route_distance' => 6.73,
                'route_duration' => 15,
                'observed_real_world_fare' => 22.00,
                'estimated_fare' => 18.77,
                'difference_percentage' => 17.21,
                'recommended_multiplier' => 1.1721,
                'observed_at' => now(),
                'trip_category' => 'airport_to_suburb',
                'notes' => 'Out-of-sample validation observation (Batch 2): Airport to Wilmslow. Real price: £22.00. TaxiScanner midpoint used for comparison: £18.77 (applied multiplier 1.0000 fallback). Recommended multiplier: 1.1721.',
                'is_outlier' => false,
                'metadata' => [
                    'source' => 'user_confirmed_booking_price',
                    'validation_type' => 'out_of_sample',
                    'validation_batch' => 2,
                    'real_price' => 22.00,
                    'taxiscanner_midpoint_used' => 18.77,
                    'applied_multiplier' => 1.0000,
                    'taxiscanner_estimate_range' => ['min' => 17.83, 'max' => 19.71, 'midpoint' => 18.77],
                    'taxiscanner_estimate_midpoint' => 18.77,
                ],
            ]
        );

        // Observation 23: Route 3 - Manchester Airport → Altrincham Town Centre Partnership (Batch 2)
        // Category: airport_to_suburb (5.66 miles) - OUT-OF-SAMPLE VALIDATION
        CalibrationObservation::updateOrCreate(
            [
                'provider_slug' => 'streetcars',
                'pickup' => 'Manchester Airport (MAN), Select terminal pickup point',
                'dropoff' => 'Altrincham Town Centre Partnership, Greenwood Street, Altrincham, UK',
                'notes' => 'Out-of-sample validation observation (Batch 2): Airport to Altrincham. Real price: £19.00. TaxiScanner midpoint used for comparison: £17.36 (applied multiplier 1.0000 fallback). Recommended multiplier: 1.0945.',
            ],
            [
                'provider_id' => $provider->id,
                'route_distance' => 5.66,
                'route_duration' => 18,
                'observed_real_world_fare' => 19.00,
                'estimated_fare' => 17.36,
                'difference_percentage' => 9.45,
                'recommended_multiplier' => 1.0945,
                'observed_at' => now(),
                'trip_category' => 'airport_to_suburb',
                'notes' => 'Out-of-sample validation observation (Batch 2): Airport to Altrincham. Real price: £19.00. TaxiScanner midpoint used for comparison: £17.36 (applied multiplier 1.0000 fallback). Recommended multiplier: 1.0945.',
                'is_outlier' => false,
                'metadata' => [
                    'source' => 'user_confirmed_booking_price',
                    'validation_type' => 'out_of_sample',
                    'validation_batch' => 2,
                    'real_price' => 19.00,
                    'taxiscanner_midpoint_used' => 17.36,
                    'applied_multiplier' => 1.0000,
                    'taxiscanner_estimate_range' => ['min' => 16.49, 'max' => 18.23, 'midpoint' => 17.36],
                    'taxiscanner_estimate_midpoint' => 17.36,
                ],
            ]
        );

        // Observation 24: Route 4 - Manchester Airport → Handforth
        // Category: airport_to_suburb (3.48 miles) - OUT-OF-SAMPLE VALIDATION
        CalibrationObservation::updateOrCreate(
            [
                'provider_slug' => 'streetcars',
                'pickup' => 'Manchester Airport (MAN), Select terminal pickup point',
                'dropoff' => 'Handforth, Wilmslow, UK',
                'observed_real_world_fare' => 22.00,
            ],
            [
                'provider_id' => $provider->id,
                'route_distance' => 3.48,
                'route_duration' => 10,
                'estimated_fare' => 13.07,
                'difference_percentage' => 68.32,
                'recommended_multiplier' => 1.6832,
                'observed_at' => now(),
                'trip_category' => 'airport_to_suburb',
                'notes' => 'Out-of-sample validation observation (Batch 2): Airport to Handforth. Real price: £22.00. TaxiScanner midpoint used for comparison: £13.07 (applied multiplier 1.0000 fallback). Recommended multiplier: 1.6832.',
                'is_outlier' => false,
                'metadata' => [
                    'source' => 'user_confirmed_booking_price',
                    'validation_type' => 'out_of_sample',
                    'validation_batch' => 2,
                    'real_price' => 22.00,
                    'taxiscanner_midpoint_used' => 13.07,
                    'applied_multiplier' => 1.0000,
                    'taxiscanner_estimate_range' => ['min' => 12.42, 'max' => 13.72, 'midpoint' => 13.07],
                    'taxiscanner_estimate_midpoint' => 13.07,
                ],
            ]
        );

        // Observation 25: Route 1 - Manchester Airport → Sale
        // Category: airport_to_suburb (8.01 miles) - OUT-OF-SAMPLE VALIDATION BATCH 3
        CalibrationObservation::updateOrCreate(
            [
                'provider_slug' => 'streetcars',
                'pickup' => 'Manchester Airport (MAN), Select terminal pickup point',
                'dropoff' => 'Sale, Greater Manchester, UK',
                'observed_real_world_fare' => 22.00,
            ],
            [
                'provider_id' => $provider->id,
                'route_distance' => 8.01,
                'route_duration' => 15,
                'estimated_fare' => 20.82,
                'difference_percentage' => 5.67,
                'recommended_multiplier' => 1.0567,
                'observed_at' => now(),
                'trip_category' => 'airport_to_suburb',
                'notes' => 'Out-of-sample validation observation (Batch 3): Airport to Sale. Real price: £22.00. TaxiScanner midpoint used for comparison: £20.82 (applied multiplier 1.0000 fallback). Recommended multiplier: 1.0567.',
                'is_outlier' => false,
                'metadata' => [
                    'source' => 'user_confirmed_booking_price',
                    'validation_type' => 'out_of_sample',
                    'validation_batch' => 3,
                    'real_price' => 22.00,
                    'taxiscanner_midpoint_used' => 20.82,
                    'applied_multiplier' => 1.0000,
                    'taxiscanner_estimate_range' => ['min' => 19.78, 'max' => 21.86, 'midpoint' => 20.82],
                    'taxiscanner_estimate_midpoint' => 20.82,
                ],
            ]
        );

        // Observation 26: Route 2 - Manchester Airport → Urmston
        // Category: airport_to_suburb (9.32 miles) - OUT-OF-SAMPLE VALIDATION BATCH 3
        CalibrationObservation::updateOrCreate(
            [
                'provider_slug' => 'streetcars',
                'pickup' => 'Manchester Airport (MAN), Select terminal pickup point',
                'dropoff' => 'Urmston, Greater Manchester, UK',
                'observed_real_world_fare' => 30.00,
            ],
            [
                'provider_id' => $provider->id,
                'route_distance' => 9.32,
                'route_duration' => 18,
                'estimated_fare' => 23.21,
                'difference_percentage' => 29.25,
                'recommended_multiplier' => 1.2925,
                'observed_at' => now(),
                'trip_category' => 'airport_to_suburb',
                'notes' => 'Out-of-sample validation observation (Batch 3): Airport to Urmston. Real price: £30.00. TaxiScanner midpoint used for comparison: £23.21 (applied multiplier 1.0000 fallback). Recommended multiplier: 1.2925.',
                'is_outlier' => false,
                'metadata' => [
                    'source' => 'user_confirmed_booking_price',
                    'validation_type' => 'out_of_sample',
                    'validation_batch' => 3,
                    'real_price' => 30.00,
                    'taxiscanner_midpoint_used' => 23.21,
                    'applied_multiplier' => 1.0000,
                    'taxiscanner_estimate_range' => ['min' => 22.05, 'max' => 24.37, 'midpoint' => 23.21],
                    'taxiscanner_estimate_midpoint' => 23.21,
                ],
            ]
        );

        // Observation 27: Route 3 - Manchester Airport → Stretford
        // Category: airport_to_suburb (8.02 miles) - OUT-OF-SAMPLE VALIDATION BATCH 3
        CalibrationObservation::updateOrCreate(
            [
                'provider_slug' => 'streetcars',
                'pickup' => 'Manchester Airport (MAN), Select terminal pickup point',
                'dropoff' => 'Stretford, Greater Manchester, UK',
                'observed_real_world_fare' => 26.00,
            ],
            [
                'provider_id' => $provider->id,
                'route_distance' => 8.02,
                'route_duration' => 14,
                'estimated_fare' => 20.73,
                'difference_percentage' => 25.42,
                'recommended_multiplier' => 1.2542,
                'observed_at' => now(),
                'trip_category' => 'airport_to_suburb',
                'notes' => 'Out-of-sample validation observation (Batch 3): Airport to Stretford. Real price: £26.00. TaxiScanner midpoint used for comparison: £20.73 (applied multiplier 1.0000 fallback). Recommended multiplier: 1.2542.',
                'is_outlier' => false,
                'metadata' => [
                    'source' => 'user_confirmed_booking_price',
                    'validation_type' => 'out_of_sample',
                    'validation_batch' => 3,
                    'real_price' => 26.00,
                    'taxiscanner_midpoint_used' => 20.73,
                    'applied_multiplier' => 1.0000,
                    'taxiscanner_estimate_range' => ['min' => 19.69, 'max' => 21.77, 'midpoint' => 20.73],
                    'taxiscanner_estimate_midpoint' => 20.73,
                ],
            ]
        );

        // Observation 28: Route 4 - Manchester Airport → Knutsford
        // Category: airport_to_suburb (11.60 miles) - OUT-OF-SAMPLE VALIDATION BATCH 3
        CalibrationObservation::updateOrCreate(
            [
                'provider_slug' => 'streetcars',
                'pickup' => 'Manchester Airport (MAN), Select terminal pickup point',
                'dropoff' => 'Knutsford, Cheshire, UK',
                'observed_real_world_fare' => 23.70,
            ],
            [
                'provider_id' => $provider->id,
                'route_distance' => 11.60,
                'route_duration' => 20,
                'estimated_fare' => 27.06,
                'difference_percentage' => -12.42,
                'recommended_multiplier' => 0.8758,
                'observed_at' => now(),
                'trip_category' => 'airport_to_suburb',
                'notes' => 'Out-of-sample validation observation (Batch 3): Airport to Knutsford. Real price: £23.70. TaxiScanner midpoint used for comparison: £27.06 (applied multiplier 1.0000 fallback). Recommended multiplier: 0.8758.',
                'is_outlier' => false,
                'metadata' => [
                    'source' => 'user_confirmed_booking_price',
                    'validation_type' => 'out_of_sample',
                    'validation_batch' => 3,
                    'real_price' => 23.70,
                    'taxiscanner_midpoint_used' => 27.06,
                    'applied_multiplier' => 1.0000,
                    'taxiscanner_estimate_range' => ['min' => 25.71, 'max' => 28.41, 'midpoint' => 27.06],
                    'taxiscanner_estimate_midpoint' => 27.06,
                ],
            ]
        );

        // Observation 29: Route 5 - Manchester Airport → Macclesfield
        // Category: airport_to_suburb (13.49 miles) - OUT-OF-SAMPLE VALIDATION BATCH 3
        CalibrationObservation::updateOrCreate(
            [
                'provider_slug' => 'streetcars',
                'pickup' => 'Manchester Airport (MAN), Select terminal pickup point',
                'dropoff' => 'Macclesfield, Cheshire, UK',
                'observed_real_world_fare' => 29.10,
            ],
            [
                'provider_id' => $provider->id,
                'route_distance' => 13.49,
                'route_duration' => 28,
                'estimated_fare' => 30.88,
                'difference_percentage' => -5.76,
                'recommended_multiplier' => 0.9424,
                'observed_at' => now(),
                'trip_category' => 'airport_to_suburb',
                'notes' => 'Out-of-sample validation observation (Batch 3): Airport to Macclesfield. Real price: £29.10. TaxiScanner midpoint used for comparison: £30.88 (applied multiplier 1.0000 fallback). Recommended multiplier: 0.9424.',
                'is_outlier' => false,
                'metadata' => [
                    'source' => 'user_confirmed_booking_price',
                    'validation_type' => 'out_of_sample',
                    'validation_batch' => 3,
                    'real_price' => 29.10,
                    'taxiscanner_midpoint_used' => 30.88,
                    'applied_multiplier' => 1.0000,
                    'taxiscanner_estimate_range' => ['min' => 29.34, 'max' => 32.42, 'midpoint' => 30.88],
                    'taxiscanner_estimate_midpoint' => 30.88,
                ],
            ]
        );

        // Observation 30: Manchester Airport → Stockport
        // Category: airport_to_suburb (8.38 miles) - Confirmed Real StreetCars Observation
        CalibrationObservation::updateOrCreate(
            [
                'provider_slug' => 'streetcars',
                'pickup' => 'Manchester Airport (MAN)',
                'dropoff' => 'Stockport, UK',
                'observed_real_world_fare' => 28.00,
            ],
            [
                'provider_id' => $provider->id,
                'route_distance' => 8.38,
                'route_duration' => 17,
                'estimated_fare' => 21.61,
                'difference_percentage' => 29.57,
                'recommended_multiplier' => 1.2957,
                'observed_at' => now(),
                'trip_category' => 'airport_to_suburb',
                'notes' => 'Confirmed second StreetCars observation: Manchester Airport (MAN) to Stockport, UK. Real price: £28.00. Base TaxiScanner subtotal: £21.61. Implied multiplier: 1.2957.',
                'is_outlier' => false,
                'metadata' => [
                    'source' => 'user_confirmed_booking_price',
                    'real_price' => 28.00,
                    'taxiscanner_midpoint_used' => 21.61,
                    'taxiscanner_estimate_range' => ['min' => 20.53, 'max' => 22.69, 'midpoint' => 21.61],
                    'taxiscanner_estimate_midpoint' => 21.61,
                    'implied_multiplier' => 1.2957,
                ],
            ]
        );

        // Observation 31: Manchester Airport → Didsbury (Sample 1)
        // Category: airport_to_suburb (6.81 miles) - Confirmed Real StreetCars Observation
        CalibrationObservation::updateOrCreate(
            [
                'provider_slug' => 'streetcars',
                'pickup' => 'Manchester Airport (MAN)',
                'dropoff' => 'Didsbury, Manchester, UK',
                'observed_real_world_fare' => 22.00,
                'notes' => 'Confirmed StreetCars observation (Sample 1): Manchester Airport (MAN) to Didsbury, Manchester, UK. Real price: £22.00. Base TaxiScanner subtotal: £19.10. Implied multiplier: 1.1518.',
            ],
            [
                'provider_id' => $provider->id,
                'route_distance' => 6.81,
                'route_duration' => 17,
                'estimated_fare' => 19.10,
                'difference_percentage' => 15.18,
                'recommended_multiplier' => 1.1518,
                'observed_at' => now(),
                'trip_category' => 'airport_to_suburb',
                'notes' => 'Confirmed StreetCars observation (Sample 1): Manchester Airport (MAN) to Didsbury, Manchester, UK. Real price: £22.00. Base TaxiScanner subtotal: £19.10. Implied multiplier: 1.1518.',
                'is_outlier' => false,
                'metadata' => [
                    'source' => 'user_confirmed_booking_price',
                    'real_price' => 22.00,
                    'taxiscanner_midpoint_used' => 19.10,
                    'taxiscanner_estimate_range' => ['min' => 18.15, 'max' => 20.06, 'midpoint' => 19.10],
                    'taxiscanner_estimate_midpoint' => 19.10,
                    'implied_multiplier' => 1.1518,
                ],
            ]
        );

        // Observation 32: Manchester Airport → Didsbury (Sample 2)
        // Category: airport_to_suburb (6.81 miles) - Confirmed Real StreetCars Observation
        CalibrationObservation::updateOrCreate(
            [
                'provider_slug' => 'streetcars',
                'pickup' => 'Manchester Airport (MAN)',
                'dropoff' => 'Didsbury, Manchester, Greater Manchester, UK',
                'observed_real_world_fare' => 22.00,
                'notes' => 'Confirmed StreetCars observation (Sample 2): Manchester Airport (MAN) to Didsbury, Manchester, UK. Real price: £22.00. Base TaxiScanner subtotal: £19.10. Implied multiplier: 1.1518.',
            ],
            [
                'provider_id' => $provider->id,
                'route_distance' => 6.81,
                'route_duration' => 17,
                'estimated_fare' => 19.10,
                'difference_percentage' => 15.18,
                'recommended_multiplier' => 1.1518,
                'observed_at' => now(),
                'trip_category' => 'airport_to_suburb',
                'notes' => 'Confirmed StreetCars observation (Sample 2): Manchester Airport (MAN) to Didsbury, Manchester, UK. Real price: £22.00. Base TaxiScanner subtotal: £19.10. Implied multiplier: 1.1518.',
                'is_outlier' => false,
                'metadata' => [
                    'source' => 'user_confirmed_booking_price',
                    'real_price' => 22.00,
                    'taxiscanner_midpoint_used' => 19.10,
                    'taxiscanner_estimate_range' => ['min' => 18.15, 'max' => 20.06, 'midpoint' => 19.10],
                    'taxiscanner_estimate_midpoint' => 19.10,
                    'implied_multiplier' => 1.1518,
                ],
            ]
        );
    }
}
