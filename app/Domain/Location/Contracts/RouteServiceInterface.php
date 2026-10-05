<?php

declare(strict_types=1);

namespace App\Domain\Location\Contracts;

use App\Domain\Location\DTOs\Location;
use App\Domain\Location\DTOs\RouteInformation;
use App\Domain\Location\Exceptions\RouteCalculationException;

interface RouteServiceInterface
{
    /**
     * Calculate driving route distance, duration and geometry between origin and destination.
     *
     * @throws RouteCalculationException
     */
    public function calculateRoute(Location $origin, Location $destination): RouteInformation;
}
