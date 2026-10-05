<?php

declare(strict_types=1);

namespace App\Domain\Taxi\Enums;

enum Currency: string
{
    case GBP = 'GBP';
    case EUR = 'EUR';
    case USD = 'USD';

    public function symbol(): string
    {
        return match ($this) {
            self::GBP => '£',
            self::EUR => '€',
            self::USD => '$',
        };
    }
}
