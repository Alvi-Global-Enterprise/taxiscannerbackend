<?php

declare(strict_types=1);

namespace App\Domain\Taxi\Enums;

enum TaxiProvider: string
{
    case UBER = 'uber';
    case BOLT = 'bolt';
    case STREETCARS = 'streetcars';
    case VEEZU = 'veezu';

    public function displayName(): string
    {
        return match ($this) {
            self::UBER => 'Uber',
            self::BOLT => 'Bolt',
            self::STREETCARS => 'StreetCars',
            self::VEEZU => 'Veezu',
        };
    }

    public function defaultBookingUrl(): string
    {
        return match ($this) {
            self::UBER => 'https://m.uber.com/ul/?action=setPickup',
            self::BOLT => 'https://bolt.eu',
            self::STREETCARS => 'https://www.streetcarsmanchester.co.uk',
            self::VEEZU => 'https://www.veezu.co.uk',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
