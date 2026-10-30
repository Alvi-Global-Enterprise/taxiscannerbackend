<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Location\Services\TownZoneResolver;
use App\Domain\Taxi\Models\Provider;
use App\Domain\Taxi\Models\ProviderPostcode;
use App\Domain\Taxi\Models\ProviderTown;
use App\Domain\Taxi\Services\UkPostcodeNormalizer;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProviderAdminController extends Controller
{
    public function __construct(
        private readonly UkPostcodeNormalizer $normalizer,
        private readonly TownZoneResolver $townZoneResolver,
    ) {}

    public function index(): JsonResponse
    {
        $providers = Provider::query()
            ->with(['postcodes', 'towns', 'activePricingConfig'])
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Provider $p) => $this->serializeProvider($p));

        return response()->json([
            'success' => true,
            'data' => $providers,
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $provider = Provider::query()
            ->where('slug', $slug)
            ->with(['postcodes', 'towns', 'activePricingConfig'])
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $this->serializeProvider($provider),
        ]);
    }

    public function townCatalogue(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->townZoneResolver->catalogue(),
        ]);
    }

    public function updateCoverage(Request $request, string $slug): JsonResponse
    {
        $provider = Provider::query()->where('slug', $slug)->firstOrFail();

        $validated = $request->validate([
            'coverage_mode' => ['required', Rule::in([
                Provider::COVERAGE_NATIONWIDE,
                Provider::COVERAGE_POSTCODES,
                Provider::COVERAGE_TOWNS,
                Provider::COVERAGE_BOTH,
            ])],
            'postcodes' => ['sometimes', 'array'],
            'postcodes.*' => ['string', 'min:2', 'max:16'],
            'towns' => ['sometimes', 'array'],
            'towns.*' => ['string', 'min:1', 'max:64'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($provider, $validated) {
            $provider->coverage_mode = $validated['coverage_mode'];

            if (array_key_exists('is_active', $validated)) {
                $provider->is_active = (bool) $validated['is_active'];
            }

            $provider->save();

            if (array_key_exists('postcodes', $validated)) {
                $this->syncPostcodes($provider, $validated['postcodes']);
            }

            if (array_key_exists('towns', $validated)) {
                $this->syncTowns($provider, $validated['towns']);
            }
        });

        return response()->json([
            'success' => true,
            'data' => $this->serializeProvider($provider->fresh(['postcodes', 'towns', 'activePricingConfig'])),
            'message' => 'Provider coverage updated.',
        ]);
    }

    public function syncPostcodesOnly(Request $request, string $slug): JsonResponse
    {
        $provider = Provider::query()->where('slug', $slug)->firstOrFail();

        $validated = $request->validate([
            'postcodes' => ['required', 'array'],
            'postcodes.*' => ['string', 'min:2', 'max:16'],
        ]);

        $this->syncPostcodes($provider, $validated['postcodes']);

        if (
            ! in_array($provider->coverage_mode, [
                Provider::COVERAGE_POSTCODES,
                Provider::COVERAGE_BOTH,
            ], true)
            && count($validated['postcodes']) > 0
        ) {
            $provider->coverage_mode = Provider::COVERAGE_POSTCODES;
            $provider->save();
        }

        return response()->json([
            'success' => true,
            'data' => $this->serializeProvider($provider->fresh(['postcodes', 'towns', 'activePricingConfig'])),
            'message' => 'Provider postcodes updated.',
        ]);
    }

    public function syncTownsOnly(Request $request, string $slug): JsonResponse
    {
        $provider = Provider::query()->where('slug', $slug)->firstOrFail();

        $validated = $request->validate([
            'towns' => ['present', 'array'],
            'towns.*' => ['nullable', 'string', 'max:64'],
        ]);

        /** @var list<string> $towns */
        $towns = array_values(array_filter(
            array_map(static fn ($v) => is_string($v) ? trim($v) : '', $validated['towns']),
            static fn (string $v) => $v !== ''
        ));

        $this->syncTowns($provider, $towns);

        if (
            ! in_array($provider->coverage_mode, [
                Provider::COVERAGE_TOWNS,
                Provider::COVERAGE_BOTH,
            ], true)
            && count($towns) > 0
        ) {
            // Keep postcode assignments usable: switch to both if postcodes already set
            $provider->coverage_mode = $provider->postcodes()->exists()
                ? Provider::COVERAGE_BOTH
                : Provider::COVERAGE_TOWNS;
            $provider->save();
        }

        return response()->json([
            'success' => true,
            'data' => $this->serializeProvider($provider->fresh(['postcodes', 'towns', 'activePricingConfig'])),
            'message' => 'Provider towns updated.',
        ]);
    }

    /**
     * @param  list<string>  $postcodes
     */
    private function syncPostcodes(Provider $provider, array $postcodes): void
    {
        $provider->postcodes()->delete();

        $seen = [];

        foreach ($postcodes as $raw) {
            $outcode = $this->normalizer->outcode($raw);
            $normalized = $this->normalizer->normalize($raw) ?? strtoupper(trim($raw));

            if ($outcode === null || isset($seen[$outcode])) {
                continue;
            }

            $seen[$outcode] = true;

            ProviderPostcode::create([
                'provider_id' => $provider->id,
                'postcode' => $normalized,
                'postcode_outcode' => $outcode,
            ]);
        }
    }

    /**
     * @param  list<string>  $townIds
     */
    private function syncTowns(Provider $provider, array $townIds): void
    {
        $validIds = [];
        foreach ($this->townZoneResolver->catalogue() as $zone) {
            $validIds[$zone['id']] = true;
        }

        $provider->towns()->delete();

        $seen = [];
        foreach ($townIds as $raw) {
            $townId = strtolower(trim($raw));
            if ($townId === '' || isset($seen[$townId]) || ! isset($validIds[$townId])) {
                continue;
            }
            $seen[$townId] = true;

            ProviderTown::create([
                'provider_id' => $provider->id,
                'town_id' => $townId,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeProvider(Provider $provider): array
    {
        return [
            'id' => $provider->id,
            'slug' => $provider->slug,
            'name' => $provider->name,
            'display_name' => $provider->display_name,
            'is_active' => $provider->is_active,
            'coverage_mode' => $provider->coverage_mode ?? Provider::COVERAGE_NATIONWIDE,
            'logo_url' => $provider->logo_url,
            'booking_url_template' => $provider->booking_url_template,
            'sort_order' => $provider->sort_order,
            'postcodes' => $provider->relationLoaded('postcodes')
                ? $provider->postcodes->pluck('postcode_outcode')->values()->all()
                : [],
            'towns' => $provider->relationLoaded('towns')
                ? $provider->towns->pluck('town_id')->values()->all()
                : [],
            'pricing' => $provider->activePricingConfig ? [
                'base_fare' => $provider->activePricingConfig->base_fare,
                'per_mile_rate' => $provider->activePricingConfig->per_mile_rate,
                'per_minute_rate' => $provider->activePricingConfig->per_minute_rate,
                'minimum_fare' => $provider->activePricingConfig->minimum_fare,
                'booking_fee' => $provider->activePricingConfig->booking_fee,
                'airport_fee' => $provider->activePricingConfig->airport_fee,
            ] : null,
        ];
    }
}
