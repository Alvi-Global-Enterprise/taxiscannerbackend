<?php

declare(strict_types=1);

namespace App\Domain\Taxi\Contracts;

use App\Domain\Location\DTOs\RouteInformation;
use App\Domain\Taxi\DTOs\TaxiQuote;
use App\Domain\Taxi\DTOs\TripRequest;
use App\Domain\Taxi\Enums\TaxiProvider;

interface TaxiProviderInterface
{
    /**
     * Unique provider identifier enum.
     */
    public function getProvider(): TaxiProvider;

    /**
     * Human-readable provider display name (e.g., 'Uber', 'Bolt').
     */
    public function getDisplayName(): string;

    /**
     * Check if this provider is currently enabled in application configuration.
     */
    public function isEnabled(): bool;

    /**
     * Generate an estimate or live quote for the given trip and route.
     *
     * Today: Delegates to EstimateEngine & PricingStrategy (QuoteType::ESTIMATE).
     * Future: Delegates to authorized provider API client (QuoteType::LIVE).
     */
    public function getEstimate(TripRequest $trip, RouteInformation $route): TaxiQuote;
}
