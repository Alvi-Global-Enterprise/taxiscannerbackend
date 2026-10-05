<?php

declare(strict_types=1);

namespace App\Domain\Taxi\DTOs;

use DateTimeImmutable;

final readonly class TripRequest
{
    public function __construct(
        public string $pickupQuery,
        public string $dropoffQuery,
        public DateTimeImmutable $requestedAt = new DateTimeImmutable,
        public int $passengers = 1,
        public int $luggage = 0,
        public string $vehicleType = 'standard',
        public array $metadata = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            pickupQuery: (string) ($data['pickup'] ?? $data['pickup_query'] ?? ''),
            dropoffQuery: (string) ($data['dropoff'] ?? $data['dropoff_query'] ?? ''),
            requestedAt: isset($data['requested_at'])
                ? new DateTimeImmutable((string) $data['requested_at'])
                : new DateTimeImmutable,
            passengers: isset($data['passengers']) ? (int) $data['passengers'] : 1,
            luggage: isset($data['luggage']) ? (int) $data['luggage'] : 0,
            vehicleType: (string) ($data['vehicle_type'] ?? 'standard'),
            metadata: (array) ($data['metadata'] ?? []),
        );
    }

    public function toArray(): array
    {
        return [
            'pickup_query' => $this->pickupQuery,
            'dropoff_query' => $this->dropoffQuery,
            'requested_at' => $this->requestedAt->format(DateTimeImmutable::ATOM),
            'passengers' => $this->passengers,
            'luggage' => $this->luggage,
            'vehicle_type' => $this->vehicleType,
            'metadata' => $this->metadata,
        ];
    }
}
