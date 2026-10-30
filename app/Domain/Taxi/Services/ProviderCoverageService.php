<?php

declare(strict_types=1);

namespace App\Domain\Taxi\Services;

use App\Domain\Taxi\Models\Provider;
use Illuminate\Support\Facades\Schema;
use Psr\Log\LoggerInterface;
use Throwable;

final class ProviderCoverageService
{
    public function __construct(
        private readonly UkPostcodeNormalizer $normalizer,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Returns provider slugs allowed to quote for the pickup area.
     * Returns null when coverage filtering is disabled.
     *
     * @param  list<string>|null  $townIds  All matching zone ids for the pickup (city/region/nation/all_uk)
     * @return list<string>|null
     */
    public function matchingProviderSlugs(?string $pickupPostcode, ?array $townIds = null): ?array
    {
        if (! (bool) config('taxiscanner.coverage.enabled', true)) {
            return null;
        }

        try {
            if (! Schema::hasTable('providers')) {
                return null;
            }

            $outcode = $this->normalizer->outcode($pickupPostcode);
            $hasPostcodesTable = Schema::hasTable('provider_postcodes');
            $hasTownsTable = Schema::hasTable('provider_towns');

            $townIds = array_values(array_filter(array_map(
                static fn ($id) => is_string($id) ? trim($id) : '',
                $townIds ?? []
            ), static fn (string $id) => $id !== ''));

            $query = Provider::query()->where('is_active', true);

            $query->where(function ($q) use ($outcode, $townIds, $hasPostcodesTable, $hasTownsTable) {
                $q->where('coverage_mode', Provider::COVERAGE_NATIONWIDE);

                if ($hasPostcodesTable && $outcode !== null) {
                    $q->orWhere(function ($q2) use ($outcode) {
                        $q2->whereIn('coverage_mode', [
                            Provider::COVERAGE_POSTCODES,
                            Provider::COVERAGE_BOTH,
                        ])->whereHas('postcodes', function ($q3) use ($outcode) {
                            $q3->where('postcode_outcode', $outcode);
                        });
                    });
                }

                if ($hasTownsTable && $townIds !== []) {
                    $q->orWhere(function ($q2) use ($townIds) {
                        $q2->whereIn('coverage_mode', [
                            Provider::COVERAGE_TOWNS,
                            Provider::COVERAGE_BOTH,
                        ])->whereHas('towns', function ($q3) use ($townIds) {
                            $q3->whereIn('town_id', $townIds);
                        });
                    });
                }
            });

            /** @var list<string> $slugs */
            $slugs = $query->orderBy('sort_order')->pluck('slug')->all();

            return $slugs;
        } catch (Throwable $e) {
            $this->logger->warning('Provider coverage lookup failed; skipping filter', [
                'error' => $e->getMessage(),
                'pickup_postcode' => $pickupPostcode,
                'town_ids' => $townIds,
            ]);

            return null;
        }
    }
}
