<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Taxi\Contracts\TaxiProviderInterface;
use App\Domain\Taxi\Enums\TaxiProvider;
use App\Domain\Taxi\Providers\TaxiProviderRegistry;
use Tests\TestCase;

class TaxiProviderRegistryTest extends TestCase
{
    public function test_registry_can_register_and_retrieve_providers(): void
    {
        $registry = new TaxiProviderRegistry;

        $mockUber = $this->createMock(TaxiProviderInterface::class);
        $mockUber->method('getProvider')->willReturn(TaxiProvider::UBER);
        $mockUber->method('isEnabled')->willReturn(true);

        $mockBolt = $this->createMock(TaxiProviderInterface::class);
        $mockBolt->method('getProvider')->willReturn(TaxiProvider::BOLT);
        $mockBolt->method('isEnabled')->willReturn(false);

        $registry->register($mockUber);
        $registry->register($mockBolt);

        $this->assertSame($mockUber, $registry->get(TaxiProvider::UBER));
        $this->assertSame($mockUber, $registry->get('uber'));
        $this->assertSame($mockBolt, $registry->get(TaxiProvider::BOLT));
        $this->assertNull($registry->get(TaxiProvider::STREETCARS));

        $all = $registry->all();
        $this->assertCount(2, $all);

        $active = $registry->active();
        $this->assertCount(1, $active);
        $this->assertArrayHasKey('uber', $active);
        $this->assertArrayNotHasKey('bolt', $active);
    }
}
