<?php

declare(strict_types=1);

namespace App\Domain\Taxi\Providers;

use App\Domain\Taxi\Enums\TaxiProvider;

class BoltEstimateProvider extends AbstractTaxiProvider
{
    public function getProvider(): TaxiProvider
    {
        return TaxiProvider::BOLT;
    }

    /**
     * Future Extension Point:
     * When an authorized Bolt API becomes available,
     * inject `BoltAuthorizedApiClient` and override `getEstimate()` here.
     */
}
