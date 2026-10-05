<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Taxi\Enums\TaxiProvider;
use App\Domain\Taxi\Models\Provider;
use App\Domain\Taxi\Models\ProviderPricingConfig;
use Illuminate\Database\Seeder;

class ProviderSeeder extends Seeder
{
    public function run(): void
    {
        $providersData = [
            [
                'slug' => TaxiProvider::UBER->value,
                'name' => 'Uber',
                'display_name' => 'Uber',
                'is_active' => true,
                'logo_url' => '/logos/uber.svg',
                'booking_url_template' => TaxiProvider::UBER->defaultBookingUrl(),
                'sort_order' => 1,
                'pricing' => [
                    'base_fare' => 2.50,
                    'per_mile_rate' => 1.40,
                    'per_minute_rate' => 0.15,
                    'minimum_fare' => 4.50,
                    'booking_fee' => 0.50,
                    'airport_fee' => 4.00,
                    'dynamic_multiplier' => 1.00,
                    'calibration_multiplier' => 1.0000,
                    'currency' => 'GBP',
                    'is_active' => true,
                    'config_data' => [
                        'notes' => 'TaxiScanner estimation assumptions - not live provider pricing',
                        'estimate_low_multiplier' => 0.95,
                        'estimate_high_multiplier' => 1.05,
                    ],
                ],
            ],
            [
                'slug' => TaxiProvider::BOLT->value,
                'name' => 'Bolt',
                'display_name' => 'Bolt',
                'is_active' => true,
                'logo_url' => '/logos/bolt.svg',
                'booking_url_template' => TaxiProvider::BOLT->defaultBookingUrl(),
                'sort_order' => 2,
                'pricing' => [
                    'base_fare' => 2.20,
                    'per_mile_rate' => 1.35,
                    'per_minute_rate' => 0.14,
                    'minimum_fare' => 4.20,
                    'booking_fee' => 0.40,
                    'airport_fee' => 4.00,
                    'dynamic_multiplier' => 1.00,
                    'calibration_multiplier' => 1.0000,
                    'currency' => 'GBP',
                    'is_active' => true,
                    'config_data' => [
                        'notes' => 'TaxiScanner estimation assumptions - not live provider pricing',
                        'estimate_low_multiplier' => 0.95,
                        'estimate_high_multiplier' => 1.05,
                    ],
                ],
            ],
            [
                'slug' => TaxiProvider::STREETCARS->value,
                'name' => 'StreetCars',
                'display_name' => 'StreetCars',
                'is_active' => true,
                'logo_url' => '/logos/streetcars.svg',
                'booking_url_template' => TaxiProvider::STREETCARS->defaultBookingUrl(),
                'sort_order' => 3,
                'pricing' => [
                    'base_fare' => 3.00,
                    'per_mile_rate' => 1.60,
                    'per_minute_rate' => 0.10,
                    'minimum_fare' => 3.50,
                    'booking_fee' => 0.00,
                    'airport_fee' => 3.50,
                    'dynamic_multiplier' => 1.00,
                    'calibration_multiplier' => 1.0000,
                    'currency' => 'GBP',
                    'is_active' => true,
                    'config_data' => [
                        'notes' => 'TaxiScanner estimation assumptions - not live provider pricing',
                        'estimate_low_multiplier' => 0.95,
                        'estimate_high_multiplier' => 1.05,
                    ],
                ],
            ],
            [
                'slug' => TaxiProvider::VEEZU->value,
                'name' => 'Veezu',
                'display_name' => 'Veezu',
                'is_active' => true,
                'logo_url' => '/logos/veezu.svg',
                'booking_url_template' => TaxiProvider::VEEZU->defaultBookingUrl(),
                'sort_order' => 4,
                'pricing' => [
                    'base_fare' => 2.80,
                    'per_mile_rate' => 1.50,
                    'per_minute_rate' => 0.12,
                    'minimum_fare' => 4.80,
                    'booking_fee' => 0.00,
                    'airport_fee' => 3.50,
                    'dynamic_multiplier' => 1.00,
                    'calibration_multiplier' => 1.0000,
                    'currency' => 'GBP',
                    'is_active' => true,
                    'config_data' => [
                        'notes' => 'TaxiScanner estimation assumptions - not live provider pricing',
                        'estimate_low_multiplier' => 0.95,
                        'estimate_high_multiplier' => 1.05,
                    ],
                ],
            ],
        ];

        foreach ($providersData as $pData) {
            $pricing = $pData['pricing'];
            unset($pData['pricing']);

            /** @var Provider $provider */
            $provider = Provider::updateOrCreate(
                ['slug' => $pData['slug']],
                $pData
            );

            ProviderPricingConfig::updateOrCreate(
                ['provider_id' => $provider->id, 'is_active' => true],
                $pricing
            );
        }
    }
}
