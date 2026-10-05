<?php

declare(strict_types=1);

namespace App\Domain\Location\Exceptions;

use RuntimeException;
use Throwable;

class RouteCalculationException extends RuntimeException
{
    public function __construct(
        string $message = 'Unable to calculate route between the specified locations',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
