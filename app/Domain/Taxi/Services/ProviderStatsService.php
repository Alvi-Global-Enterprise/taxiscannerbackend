<?php

declare(strict_types=1);

namespace App\Domain\Taxi\Services;

use App\Domain\Taxi\Models\Provider;
use App\Domain\Taxi\Models\ProviderEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Psr\Log\LoggerInterface;
use Throwable;

final class ProviderStatsService
{
    public function __construct(
        private readonly UkPostcodeNormalizer $normalizer,
        private readonly LoggerInterface $logger,
    ) {}

    public function record(
        string $eventType,
        string $providerSlug,
        ?string $pickupPostcode = null,
        ?string $clientIp = null,
        ?array $metadata = null,
    ): void {
        if (! (bool) config('taxiscanner.stats.enabled', true)) {
            return;
        }

        try {
            if (! Schema::hasTable('provider_events')) {
                return;
            }

            $provider = Schema::hasTable('providers')
                ? Provider::query()->where('slug', $providerSlug)->first()
                : null;

            ProviderEvent::create([
                'provider_id' => $provider?->id,
                'provider_slug' => $providerSlug,
                'event_type' => $eventType,
                'pickup_postcode' => $this->normalizer->normalize($pickupPostcode),
                'pickup_outcode' => $this->normalizer->outcode($pickupPostcode),
                'client_ip_hash' => $clientIp ? hash('sha256', $clientIp) : null,
                'metadata' => $metadata,
                'occurred_at' => Carbon::now(),
            ]);
        } catch (Throwable $e) {
            $this->logger->warning('Failed to record provider event', [
                'event_type' => $eventType,
                'provider_slug' => $providerSlug,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function summary(?Carbon $from = null, ?Carbon $to = null): array
    {
        if (! Schema::hasTable('provider_events')) {
            return [];
        }

        $from ??= Carbon::now()->subDays(30);
        $to ??= Carbon::now();

        $rows = ProviderEvent::query()
            ->select([
                'provider_slug',
                'event_type',
                DB::raw('COUNT(*) as total'),
            ])
            ->whereBetween('occurred_at', [$from, $to])
            ->groupBy('provider_slug', 'event_type')
            ->get();

        $bySlug = [];

        foreach ($rows as $row) {
            $slug = (string) $row->provider_slug;
            if (! isset($bySlug[$slug])) {
                $bySlug[$slug] = [
                    'provider_slug' => $slug,
                    'compare_impressions' => 0,
                    'booking_clicks' => 0,
                ];
            }

            if ($row->event_type === ProviderEvent::TYPE_COMPARE_IMPRESSION) {
                $bySlug[$slug]['compare_impressions'] = (int) $row->total;
            }

            if ($row->event_type === ProviderEvent::TYPE_BOOKING_CLICK) {
                $bySlug[$slug]['booking_clicks'] = (int) $row->total;
            }
        }

        $providers = Schema::hasTable('providers')
            ? Provider::query()->get()->keyBy('slug')
            : collect();

        $result = [];

        foreach ($bySlug as $slug => $stats) {
            /** @var Provider|null $provider */
            $provider = $providers->get($slug);
            $result[] = [
                ...$stats,
                'display_name' => $provider?->display_name ?? $slug,
                'is_active' => $provider?->is_active ?? null,
            ];
        }

        usort($result, static fn (array $a, array $b) => $b['booking_clicks'] <=> $a['booking_clicks']);

        return $result;
    }
}
