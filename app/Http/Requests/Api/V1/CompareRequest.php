<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Domain\Taxi\DTOs\TripRequest;
use DateTimeImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CompareRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'pickup' => ['required', 'string', 'min:2', 'max:255'],
            'dropoff' => ['required', 'string', 'min:2', 'max:255', 'different:pickup'],
            'passengers' => ['sometimes', 'integer', 'min:1', 'max:8'],
            'luggage' => ['sometimes', 'integer', 'min:0', 'max:10'],
            'vehicle_type' => ['sometimes', 'string', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pickup.required' => 'Please provide a pickup location.',
            'pickup.min' => 'Pickup location must be at least 2 characters long.',
            'dropoff.required' => 'Please provide a dropoff destination.',
            'dropoff.min' => 'Dropoff destination must be at least 2 characters long.',
            'dropoff.different' => 'Pickup and dropoff locations cannot be identical.',
        ];
    }

    /**
     * Convert validated input into a domain TripRequest DTO.
     */
    public function toTripRequest(): TripRequest
    {
        return new TripRequest(
            pickupQuery: trim((string) $this->input('pickup')),
            dropoffQuery: trim((string) $this->input('dropoff')),
            requestedAt: new DateTimeImmutable,
            passengers: (int) $this->input('passengers', 1),
            luggage: (int) $this->input('luggage', 0),
            vehicleType: (string) $this->input('vehicle_type', 'standard'),
            metadata: [
                'user_agent' => $this->userAgent(),
                'ip_hash' => $this->ip() ? hash('sha256', $this->ip()) : null,
            ],
        );
    }
}
