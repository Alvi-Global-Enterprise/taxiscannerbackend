<?php

declare(strict_types=1);

namespace App\Domain\Taxi\Exceptions;

use RuntimeException;
use Throwable;

class TaxiProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $providerSlug,
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
