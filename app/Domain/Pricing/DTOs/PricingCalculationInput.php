<?php

declare(strict_types=1);

namespace App\Domain\Pricing\DTOs;

use App\Domain\Location\DTOs\RouteInformation;
use App\Domain\Taxi\DTOs\TripRequest;
use App\Domain\Taxi\Enums\TaxiProvider;
use App\Domain\Taxi\Models\ProviderPricingConfig;
use DateTimeImmutable;

final readonly class PricingCalculationInput
{
    public function __construct(
        public TaxiProvider $provider,
        public RouteInformation $route,
        public TripRequest $trip,
        public ?ProviderPricingConfig $config = null,
        public DateTimeImmutable $requestedAt = new DateTimeImmutable,
    ) {}
}
