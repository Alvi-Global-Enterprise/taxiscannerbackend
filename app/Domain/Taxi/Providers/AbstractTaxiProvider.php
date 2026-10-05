<?php

declare(strict_types=1);

namespace App\Domain\Taxi\Providers;

use App\Domain\Location\DTOs\RouteInformation;
use App\Domain\Taxi\Contracts\EstimateEngineInterface;
use App\Domain\Taxi\Contracts\TaxiProviderInterface;
use App\Domain\Taxi\DTOs\TaxiQuote;
use App\Domain\Taxi\DTOs\TripRequest;
use App\Domain\Taxi\Enums\TaxiProvider;

abstract class AbstractTaxiProvider implements TaxiProviderInterface
{
    public function __construct(
        protected readonly EstimateEngineInterface $estimateEngine,
    ) {}

    abstract public function getProvider(): TaxiProvider;

    public function getDisplayName(): string
    {
        return $this->getProvider()->displayName();
    }

    public function isEnabled(): bool
    {
        // Check configuration / database toggle (default true)
        $enabledConfig = config(sprintf('taxiscanner.providers.%s.enabled', $this->getProvider()->value), true);

        return (bool) $enabledConfig;
    }

    /**
     * Generate quote for trip & route.
     *
     * Today: Delegates to the EstimateEngine and provider-specific pricing strategy.
     * Future: An authorized provider client (e.g., AuthorizedUberApiClient) can be injected
     *         and called directly here to return a live TaxiQuote, without touching controllers or services.
     */
    public function getEstimate(TripRequest $trip, RouteInformation $route): TaxiQuote
    {
        return $this->estimateEngine->calculateEstimate($this->getProvider(), $trip, $route);
    }
}
