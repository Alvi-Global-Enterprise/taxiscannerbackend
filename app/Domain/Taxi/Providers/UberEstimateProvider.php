<?php

declare(strict_types=1);

namespace App\Domain\Taxi\Providers;

use App\Domain\Taxi\Enums\TaxiProvider;

class UberEstimateProvider extends AbstractTaxiProvider
{
    public function getProvider(): TaxiProvider
    {
        return TaxiProvider::UBER;
    }

    /**
     * Future Extension Point:
     * When an authorized Uber Ride Request / Fare Estimation API becomes available,
     * inject `UberAuthorizedApiClient` and override `getEstimate()` here to return
     * a live TaxiQuote (QuoteType::LIVE) seamlessly.
     */
}
