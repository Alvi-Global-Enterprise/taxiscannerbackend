<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Strategies;

use App\Domain\Pricing\DTOs\PricingBreakdown;
use App\Domain\Pricing\DTOs\PricingCalculationInput;
use App\Domain\Pricing\Services\StreetCarsGeographicAdjustmentService;
use App\Domain\Taxi\DTOs\PriceRange;
use App\Domain\Taxi\Enums\TaxiProvider;

class StreetCarsPricingStrategy extends AbstractPricingStrategy
{
    public function __construct(
        private readonly ?StreetCarsGeographicAdjustmentService $geoAdjustmentService = null,
    ) {}

    public function supports(TaxiProvider $provider): bool
    {
        return $provider === TaxiProvider::STREETCARS;
    }

    public function calculate(PricingCalculationInput $input): PriceRange
    {
        return parent::calculate($input);
    }

    public function calculateBreakdown(PricingCalculationInput $input): PricingBreakdown
    {
        $breakdown = parent::calculateBreakdown($input);

        $tripCategory = $this->resolveTripCategory($input);

        $service = $this->geoAdjustmentService ?? app(StreetCarsGeographicAdjustmentService::class);

        return $service->applyAdjustment($breakdown, $input, $tripCategory);
    }
}
