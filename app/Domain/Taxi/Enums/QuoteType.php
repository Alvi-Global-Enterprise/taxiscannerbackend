<?php

declare(strict_types=1);

namespace App\Domain\Taxi\Enums;

enum QuoteType: string
{
    case ESTIMATE = 'estimate';
    case LIVE = 'live';

    public function isEstimate(): bool
    {
        return $this === self::ESTIMATE;
    }

    public function isLive(): bool
    {
        return $this === self::LIVE;
    }
}
