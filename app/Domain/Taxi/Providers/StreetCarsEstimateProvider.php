<?php

declare(strict_types=1);

namespace App\Domain\Taxi\Providers;

use App\Domain\Taxi\Enums\TaxiProvider;

class StreetCarsEstimateProvider extends AbstractTaxiProvider
{
    public function getProvider(): TaxiProvider
    {
        return TaxiProvider::STREETCARS;
    }

    /**
     * Future Extension Point:
     * When an authorized StreetCars API/dispatch system (e.g., Autocab/iCabbi) becomes available,
     * inject `StreetCarsAuthorizedApiClient` and override `getEstimate()` here.
     */
}
