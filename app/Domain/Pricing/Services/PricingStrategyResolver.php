<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Pricing\Contracts\PricingStrategyInterface;
use App\Domain\Taxi\Enums\TaxiProvider;
use InvalidArgumentException;

class PricingStrategyResolver
{
    /**
     * @var array<string, PricingStrategyInterface>
     */
    private array $strategies = [];

    /**
     * @param  iterable<PricingStrategyInterface>  $strategies
     */
    public function __construct(iterable $strategies = [])
    {
        foreach ($strategies as $strategy) {
            $this->registerStrategy($strategy);
        }
    }

    public function registerStrategy(PricingStrategyInterface $strategy): void
    {
        foreach (TaxiProvider::cases() as $provider) {
            if ($strategy->supports($provider)) {
                $this->strategies[$provider->value] = $strategy;
            }
        }
    }

    public function resolve(TaxiProvider|string $provider): PricingStrategyInterface
    {
        $key = $provider instanceof TaxiProvider ? $provider->value : (string) $provider;

        if (! isset($this->strategies[$key])) {
            throw new InvalidArgumentException(sprintf('No pricing strategy found for provider [%s].', $key));
        }

        return $this->strategies[$key];
    }

    public function has(TaxiProvider|string $provider): bool
    {
        $key = $provider instanceof TaxiProvider ? $provider->value : (string) $provider;

        return isset($this->strategies[$key]);
    }
}
