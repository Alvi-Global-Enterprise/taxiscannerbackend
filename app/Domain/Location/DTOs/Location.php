<?php

declare(strict_types=1);

namespace App\Domain\Location\DTOs;

final readonly class Location
{
    public function __construct(
        public string $query,
        public string $formattedAddress,
        public Coordinates $coordinates,
        public ?string $city = null,
        public ?string $postcode = null,
        public string $country = 'GB',
        public ?string $placeId = null,
    ) {}

    public function toArray(): array
    {
        return [
            'query' => $this->query,
            'formatted_address' => $this->formattedAddress,
            'coordinates' => $this->coordinates->toArray(),
            'city' => $this->city,
            'postcode' => $this->postcode,
            'country' => $this->country,
            'place_id' => $this->placeId,
        ];
    }
}
