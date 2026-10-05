<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Contracts;

use App\Domain\Pricing\DTOs\PricingBreakdown;
use App\Domain\Pricing\DTOs\PricingCalculationInput;
use App\Domain\Taxi\DTOs\PriceRange;
use App\Domain\Taxi\Enums\TaxiProvider;

interface PricingStrategyInterface
{
    /**
     * Check if this strategy supports the given taxi provider.
     */
    public function supports(TaxiProvider $provider): bool;

    /**
     * Calculate the estimated price range based on route and trip parameters.
     * Ready for UK taxi estimation formula implementation.
     */
    public function calculate(PricingCalculationInput $input): PriceRange;

    /**
     * Optional detailed itemized cost breakdown.
     */
    public function calculateBreakdown(PricingCalculationInput $input): ?PricingBreakdown;
}
