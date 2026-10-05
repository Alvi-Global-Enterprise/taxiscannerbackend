<?php

declare(strict_types=1);

namespace App\Domain\Taxi\Enums;

enum ProviderStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case DEGRADED = 'degraded';

    public function isAvailable(): bool
    {
        return $this === self::ACTIVE;
    }
}
