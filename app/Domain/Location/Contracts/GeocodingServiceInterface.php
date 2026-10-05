<?php

declare(strict_types=1);

namespace App\Domain\Location\Contracts;

use App\Domain\Location\DTOs\Coordinates;
use App\Domain\Location\DTOs\Location;
use App\Domain\Location\Exceptions\GeocodingException;

interface GeocodingServiceInterface
{
    /**
     * Geocode an address, postcode, landmark or city into normalized Location DTO.
     *
     * @throws GeocodingException
     */
    public function geocode(string $address, ?Coordinates $proximity = null, ?string $referenceCity = null): Location;
}
