<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Taxi\Services\TaxiComparisonService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CompareRequest;
use App\Http\Resources\Api\V1\CompareResource;

class CompareController extends Controller
{
    public function __construct(
        private readonly TaxiComparisonService $comparisonService,
    ) {}

    /**
     * Compare estimated taxi fares across providers.
     *
     * POST /api/v1/compare
     */
    public function __invoke(CompareRequest $request): CompareResource
    {
        $tripRequest = $request->toTripRequest();

        $result = $this->comparisonService->compare(
            trip: $tripRequest,
            clientIp: $request->ip(),
        );

        return new CompareResource($result);
    }
}
