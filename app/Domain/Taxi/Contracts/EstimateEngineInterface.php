<?php

declare(strict_types=1);

namespace App\Domain\Taxi\Contracts;

use App\Domain\Location\DTOs\RouteInformation;
use App\Domain\Taxi\DTOs\TaxiQuote;
use App\Domain\Taxi\DTOs\TripRequest;
use App\Domain\Taxi\Enums\TaxiProvider;

interface EstimateEngineInterface
{
    /**
     * Compute an estimated fare quote for a specific provider, route, and trip request.
     */
    public function calculateEstimate(
        TaxiProvider|string $provider,
        TripRequest $trip,
        RouteInformation $route,
    ): TaxiQuote;
}
