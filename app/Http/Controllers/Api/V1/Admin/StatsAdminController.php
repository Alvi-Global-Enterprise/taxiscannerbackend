<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Taxi\Services\ProviderStatsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class StatsAdminController extends Controller
{
    public function __construct(
        private readonly ProviderStatsService $statsService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'days' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ]);

        if (isset($validated['from'])) {
            $from = Carbon::parse($validated['from'])->startOfDay();
            $to = isset($validated['to'])
                ? Carbon::parse($validated['to'])->endOfDay()
                : Carbon::now();
        } else {
            $days = (int) ($validated['days'] ?? 30);
            $from = Carbon::now()->subDays($days)->startOfDay();
            $to = Carbon::now();
        }

        $rows = $this->statsService->summary($from, $to);

        return response()->json([
            'success' => true,
            'data' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'providers' => $rows,
                'totals' => [
                    'compare_impressions' => array_sum(array_column($rows, 'compare_impressions')),
                    'booking_clicks' => array_sum(array_column($rows, 'booking_clicks')),
                ],
            ],
        ]);
    }
}
