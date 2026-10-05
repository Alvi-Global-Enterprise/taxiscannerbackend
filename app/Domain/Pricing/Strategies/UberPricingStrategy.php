<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Strategies;

use App\Domain\Pricing\DTOs\PricingBreakdown;
use App\Domain\Pricing\DTOs\PricingCalculationInput;
use App\Domain\Taxi\DTOs\PriceRange;
use App\Domain\Taxi\Enums\TaxiProvider;

class UberPricingStrategy extends AbstractPricingStrategy
{
    public function supports(TaxiProvider $provider): bool
    {
        return $provider === TaxiProvider::UBER;
    }

    public function calculate(PricingCalculationInput $input): PriceRange
    {
        return parent::calculate($input);
    }

    public function calculateBreakdown(PricingCalculationInput $input): PricingBreakdown
    {
        return parent::calculateBreakdown($input);
    }
}
