<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Taxi\Models\ProviderEvent;
use App\Domain\Taxi\Services\ProviderStatsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public endpoint for customer frontend to record booking-link clicks.
 */
class TrackClickController extends Controller
{
    public function __construct(
        private readonly ProviderStatsService $statsService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider_slug' => ['required', 'string', 'max:64'],
            'pickup_postcode' => ['sometimes', 'nullable', 'string', 'max:16'],
        ]);

        $this->statsService->record(
            eventType: ProviderEvent::TYPE_BOOKING_CLICK,
            providerSlug: $validated['provider_slug'],
            pickupPostcode: $validated['pickup_postcode'] ?? null,
            clientIp: $request->ip(),
            metadata: [
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
            ],
        );

        return response()->json([
            'success' => true,
            'message' => 'Click recorded.',
        ]);
    }
}
