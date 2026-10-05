<?php

declare(strict_types=1);

namespace App\Domain\Taxi\Contracts;

use App\Domain\Taxi\Enums\TaxiProvider;

interface TaxiProviderRegistryInterface
{
    /**
     * Register a provider implementation.
     */
    public function register(TaxiProviderInterface $provider): void;

    /**
     * Get a provider instance by enum or string slug.
     */
    public function get(TaxiProvider|string $provider): ?TaxiProviderInterface;

    /**
     * Get all registered providers.
     *
     * @return array<string, TaxiProviderInterface>
     */
    public function all(): array;

    /**
     * Get only active/enabled providers.
     *
     * @return array<string, TaxiProviderInterface>
     */
    public function active(): array;
}
