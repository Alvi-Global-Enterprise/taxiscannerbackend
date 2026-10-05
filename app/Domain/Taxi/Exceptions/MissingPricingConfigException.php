<?php

declare(strict_types=1);

namespace App\Domain\Taxi\Exceptions;

class MissingPricingConfigException extends TaxiProviderException
{
    public static function forProvider(string $providerSlug): self
    {
        return new self($providerSlug, sprintf('Missing pricing configuration for provider [%s].', $providerSlug));
    }
}
