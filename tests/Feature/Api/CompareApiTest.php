<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Location\DTOs\RouteInformation;
use App\Domain\Taxi\Contracts\TaxiProviderInterface;
use App\Domain\Taxi\Contracts\TaxiProviderRegistryInterface;
use App\Domain\Taxi\DTOs\TaxiQuote;
use App\Domain\Taxi\DTOs\TripRequest;
use App\Domain\Taxi\Enums\TaxiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CompareApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_can_compare_taxi_fares_successfully(): void
    {
        $payload = [
            'pickup' => 'Manchester Airport',
            'dropoff' => 'Manchester City Centre',
        ];

        $response = $this->postJson('/api/v1/compare', $payload);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'pickup' => [
                        'query',
                        'formatted_address',
                        'coordinates' => ['latitude', 'longitude'],
                        'city',
                        'postcode',
                        'country',
                    ],
                    'dropoff' => [
                        'query',
                        'formatted_address',
                        'coordinates' => ['latitude', 'longitude'],
                        'city',
                        'postcode',
                        'country',
                    ],
                    'route' => [
                        'distance_miles',
                        'duration_minutes',
                        'distance_meters',
                        'duration_seconds',
                        'summary',
                        'is_estimated',
                    ],
                    'quotes' => [
                        '*' => [
                            'provider',
                            'display_name',
                            'min_price',
                            'max_price',
                            'is_fixed_price',
                            'currency',
                            'estimated_pickup_minutes',
                            'estimated_duration_minutes',
                            'distance_miles',
                            'quote_type',
                            'is_available',
                            'booking_url',
                            'metadata',
                            'error_message',
                        ],
                    ],
                ],
                'meta' => [
                    'quote_type',
                    'disclaimer',
                    'execution_time_ms',
                ],
            ]);

        $responseData = $response->json();
        $this->assertTrue($responseData['success']);
        $this->assertCount(4, $responseData['data']['quotes']);

        $providerSlugs = array_column($responseData['data']['quotes'], 'provider');
        $this->assertContains('uber', $providerSlugs);
        $this->assertContains('bolt', $providerSlugs);
        $this->assertContains('streetcars', $providerSlugs);
        $this->assertContains('veezu', $providerSlugs);

        // Verify quote_type is strictly "estimate"
        foreach ($responseData['data']['quotes'] as $quote) {
            $this->assertEquals('estimate', $quote['quote_type']);
            $this->assertTrue($quote['is_available']);
        }
    }

    public function test_validation_fails_when_pickup_or_dropoff_missing(): void
    {
        $response = $this->postJson('/api/v1/compare', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['pickup', 'dropoff']);
    }

    public function test_validation_fails_when_pickup_and_dropoff_are_identical(): void
    {
        $response = $this->postJson('/api/v1/compare', [
            'pickup' => 'Manchester Airport',
            'dropoff' => 'Manchester Airport',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['dropoff']);
    }

    public function test_validation_fails_when_query_is_too_short(): void
    {
        $response = $this->postJson('/api/v1/compare', [
            'pickup' => 'M',
            'dropoff' => 'Manchester Piccadilly',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['pickup']);
    }

    public function test_provider_failure_isolation_ensures_partial_success(): void
    {
        // Replace StreetCars provider with a faulty provider that throws an exception
        $registry = app(TaxiProviderRegistryInterface::class);

        $faultyStreetCars = new class implements TaxiProviderInterface
        {
            public function getProvider(): TaxiProvider
            {
                return TaxiProvider::STREETCARS;
            }

            public function getDisplayName(): string
            {
                return 'StreetCars';
            }

            public function isEnabled(): bool
            {
                return true;
            }

            public function getEstimate(TripRequest $trip, RouteInformation $route): TaxiQuote
            {
                throw new RuntimeException('Connection timeout to provider engine');
            }
        };

        $registry->register($faultyStreetCars);

        $response = $this->postJson('/api/v1/compare', [
            'pickup' => 'Manchester Airport',
            'dropoff' => 'Manchester City Centre',
        ]);

        // The entire request MUST still return 200 OK!
        $response->assertStatus(200);

        $quotes = $response->json('data.quotes');
        $this->assertCount(4, $quotes);

        $streetcarsQuote = collect($quotes)->firstWhere('provider', 'streetcars');
        $this->assertNotNull($streetcarsQuote);
        $this->assertFalse($streetcarsQuote['is_available']);
        $this->assertNotNull($streetcarsQuote['error_message']);

        // Check that Uber, Bolt and Veezu still succeeded
        $uberQuote = collect($quotes)->firstWhere('provider', 'uber');
        $this->assertTrue($uberQuote['is_available']);

        $boltQuote = collect($quotes)->firstWhere('provider', 'bolt');
        $this->assertTrue($boltQuote['is_available']);

        $veezuQuote = collect($quotes)->firstWhere('provider', 'veezu');
        $this->assertTrue($veezuQuote['is_available']);
    }
}
