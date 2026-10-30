<?php

declare(strict_types=1);

namespace App\Domain\Taxi\DTOs;

use App\Domain\Location\DTOs\Location;
use App\Domain\Location\DTOs\RouteInformation;

final readonly class TaxiComparisonResult
{
    /**
     * @param  list<TaxiQuote>  $quotes
     * @param  array{town_id: ?string, match_type: string, display_name: ?string}|null  $matchedTown
     */
    public function __construct(
        public Location $pickup,
        public Location $dropoff,
        public RouteInformation $route,
        public array $quotes,
        public float $executionTimeMs = 0.0,
        public ?array $matchedTown = null,
    ) {}

    public function toArray(): array
    {
        return [
            'pickup' => $this->pickup->toArray(),
            'dropoff' => $this->dropoff->toArray(),
            'route' => $this->route->toArray(),
            'quotes' => array_map(static fn (TaxiQuote $q) => $q->toArray(), $this->quotes),
            'matched_town' => $this->matchedTown,
            'execution_time_ms' => $this->executionTimeMs,
        ];
    }
}
