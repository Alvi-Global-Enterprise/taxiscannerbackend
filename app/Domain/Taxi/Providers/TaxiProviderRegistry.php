<?php

declare(strict_types=1);

namespace App\Domain\Taxi\Providers;

use App\Domain\Taxi\Contracts\TaxiProviderInterface;
use App\Domain\Taxi\Contracts\TaxiProviderRegistryInterface;
use App\Domain\Taxi\Enums\TaxiProvider;

class TaxiProviderRegistry implements TaxiProviderRegistryInterface
{
    /**
     * @var array<string, TaxiProviderInterface>
     */
    private array $providers = [];

    /**
     * @param  iterable<TaxiProviderInterface>  $providers
     */
    public function __construct(iterable $providers = [])
    {
        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    public function register(TaxiProviderInterface $provider): void
    {
        $this->providers[$provider->getProvider()->value] = $provider;
    }

    public function get(TaxiProvider|string $provider): ?TaxiProviderInterface
    {
        $key = $provider instanceof TaxiProvider ? $provider->value : (string) $provider;

        return $this->providers[$key] ?? null;
    }

    /**
     * @return array<string, TaxiProviderInterface>
     */
    public function all(): array
    {
        return $this->providers;
    }

    /**
     * @return array<string, TaxiProviderInterface>
     */
    public function active(): array
    {
        return array_filter(
            $this->providers,
            static fn (TaxiProviderInterface $provider) => $provider->isEnabled()
        );
    }
}
