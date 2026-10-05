<?php

declare(strict_types=1);

namespace App\Domain\Location\Exceptions;

use RuntimeException;
use Throwable;

class GeocodingException extends RuntimeException
{
    /**
     * @param  list<array<string, mixed>>  $candidates
     */
    public function __construct(
        public readonly string $addressQuery,
        string $message = '',
        public readonly array $candidates = [],
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        $msg = $message ?: sprintf('Unable to geocode address: "%s"', $addressQuery);
        parent::__construct($msg, $code, $previous);
    }
}
