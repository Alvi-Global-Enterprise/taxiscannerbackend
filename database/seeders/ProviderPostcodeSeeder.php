<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Taxi\Models\Provider;
use App\Domain\Taxi\Models\ProviderPostcode;
use App\Domain\Taxi\Services\UkPostcodeNormalizer;
use Illuminate\Database\Seeder;

class ProviderPostcodeSeeder extends Seeder
{
    /**
     * Sample coverage for Req 2 local testing.
     * Uber/Bolt stay nationwide; StreetCars/Veezu limited to Manchester outcodes.
     */
    public function run(): void
    {
        $normalizer = new UkPostcodeNormalizer;

        $nationwide = ['uber', 'bolt'];
        foreach ($nationwide as $slug) {
            $provider = Provider::query()->where('slug', $slug)->first();
            if ($provider) {
                $provider->coverage_mode = Provider::COVERAGE_NATIONWIDE;
                $provider->save();
            }
        }

        $manchesterOutcodes = ['M1', 'M2', 'M3', 'M4', 'M14', 'M15', 'M20', 'M60', 'M90'];

        foreach (['streetcars', 'veezu'] as $slug) {
            $provider = Provider::query()->where('slug', $slug)->first();
            if (! $provider) {
                continue;
            }

            $provider->coverage_mode = Provider::COVERAGE_POSTCODES;
            $provider->save();
            $provider->postcodes()->delete();

            foreach ($manchesterOutcodes as $outcode) {
                ProviderPostcode::create([
                    'provider_id' => $provider->id,
                    'postcode' => $normalizer->normalize($outcode) ?? $outcode,
                    'postcode_outcode' => $normalizer->outcode($outcode) ?? $outcode,
                ]);
            }
        }
    }
}
