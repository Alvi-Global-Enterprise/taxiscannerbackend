<?php

declare(strict_types=1);

namespace App\Domain\Taxi\Exceptions;

class ProviderUnavailableException extends TaxiProviderException
{
    public static function forProvider(string $providerSlug, string $reason = 'Provider is currently disabled or unavailable'): self
    {
        return new self($providerSlug, sprintf('Provider [%s] is unavailable: %s', $providerSlug, $reason));
    }
}
