<?php

declare(strict_types=1);

namespace App\Domain\Taxi\Providers;

use App\Domain\Taxi\Enums\TaxiProvider;

class VeezuEstimateProvider extends AbstractTaxiProvider
{
    public function getProvider(): TaxiProvider
    {
        return TaxiProvider::VEEZU;
    }

    /**
     * Future Extension Point:
     * When an authorized Veezu API integration becomes available,
     * inject `VeezuAuthorizedApiClient` and override `getEstimate()` here.
     */
}
