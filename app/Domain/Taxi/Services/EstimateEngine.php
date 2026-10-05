<?php

declare(strict_types=1);

namespace App\Domain\Taxi\Services;

use App\Domain\Location\DTOs\RouteInformation;
use App\Domain\Pricing\DTOs\PricingCalculationInput;
use App\Domain\Pricing\Services\PricingStrategyResolver;
use App\Domain\Taxi\Contracts\EstimateEngineInterface;
use App\Domain\Taxi\DTOs\TaxiQuote;
use App\Domain\Taxi\DTOs\TripRequest;
use App\Domain\Taxi\Enums\QuoteType;
use App\Domain\Taxi\Enums\TaxiProvider;
use App\Domain\Taxi\Models\Provider;
use App\Domain\Taxi\Models\ProviderPricingConfig;
use Illuminate\Support\Facades\Schema;
use Psr\Log\LoggerInterface;

class EstimateEngine implements EstimateEngineInterface
{
    public function __construct(
        private readonly PricingStrategyResolver $strategyResolver,
        private readonly LoggerInterface $logger,
    ) {}

    public function calculateEstimate(
        TaxiProvider|string $provider,
        TripRequest $trip,
        RouteInformation $route,
    ): TaxiQuote {
        $startTime = microtime(true);
        $providerEnum = $provider instanceof TaxiProvider ? $provider : TaxiProvider::from((string) $provider);
        $providerSlug = $providerEnum->value;

        $this->logger->info('Starting estimate calculation for provider', [
            'provider' => $providerSlug,
            'distance_miles' => $route->distanceMiles,
            'duration_minutes' => $route->durationMinutes,
        ]);

        // 1. Resolve pricing strategy for this provider
        $strategy = $this->strategyResolver->resolve($providerEnum);

        // 2. Load pricing config from database (if tables exist and config is active)
        $pricingConfig = $this->resolvePricingConfig($providerSlug);

        // 3. Assemble calculation input
        $input = new PricingCalculationInput(
            provider: $providerEnum,
            route: $route,
            trip: $trip,
            config: $pricingConfig,
            requestedAt: $trip->requestedAt,
        );

        // 4. Calculate fare price range
        $priceRange = $strategy->calculate($input);
        $breakdown = $strategy->calculateBreakdown($input);

        $calculationDuration = round((microtime(true) - $startTime) * 1000, 2);

        $this->logger->info('Completed estimate calculation for provider', [
            'provider' => $providerSlug,
            'calculation_duration_ms' => $calculationDuration,
            'min_price' => $priceRange->min,
            'max_price' => $priceRange->max,
            'currency' => $priceRange->currency,
        ]);

        // 5. Construct normalized TaxiQuote (strictly QuoteType::ESTIMATE)
        return new TaxiQuote(
            provider: $providerEnum,
            providerDisplayName: $providerEnum->displayName(),
            priceRange: $priceRange,
            currency: $priceRange->currency,
            estimatedPickupMinutes: $this->resolveEstimatedPickupMinutes($providerEnum),
            estimatedDurationMinutes: $route->durationMinutes,
            distanceMiles: $route->distanceMiles,
            quoteType: QuoteType::ESTIMATE,
            isAvailable: true,
            bookingUrl: $this->resolveBookingUrl($providerEnum, $trip, $route),
            metadata: [
                'is_estimate' => true,
                'disclaimer' => 'Indicative fare estimate. Actual price may vary based on route, traffic and live surge.',
                'calculation_duration_ms' => $calculationDuration,
                'breakdown' => $breakdown?->toArray(),
            ],
            errorMessage: null,
        );
    }

    private function resolvePricingConfig(string $providerSlug): ?ProviderPricingConfig
    {
        try {
            if (class_exists(Provider::class) && Schema::hasTable('providers')) {
                /** @var Provider|null $providerModel */
                $providerModel = Provider::query()
                    ->where('slug', $providerSlug)
                    ->first();

                if ($providerModel) {
                    return $providerModel->activePricingConfig;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Unable to read provider pricing config from database; using defaults', [
                'provider' => $providerSlug,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function resolveEstimatedPickupMinutes(TaxiProvider $provider): int
    {
        // Typical UK city centre wait time range
        return match ($provider) {
            TaxiProvider::UBER => 4,
            TaxiProvider::BOLT => 5,
            TaxiProvider::STREETCARS => 7,
            TaxiProvider::VEEZU => 8,
        };
    }

    private function resolveBookingUrl(TaxiProvider $provider, TripRequest $trip, RouteInformation $route): string
    {
        return $provider->defaultBookingUrl();
    }
}
