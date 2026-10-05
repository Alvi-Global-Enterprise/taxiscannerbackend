<?php

declare(strict_types=1);

namespace App\Domain\Taxi\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderPricingConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_id',
        'base_fare',
        'per_mile_rate',
        'per_minute_rate',
        'minimum_fare',
        'booking_fee',
        'airport_fee',
        'dynamic_multiplier',
        'calibration_multiplier',
        'currency',
        'is_active',
        'effective_from',
        'effective_to',
        'config_data',
    ];

    protected $casts = [
        'base_fare' => 'float',
        'per_mile_rate' => 'float',
        'per_minute_rate' => 'float',
        'minimum_fare' => 'float',
        'booking_fee' => 'float',
        'airport_fee' => 'float',
        'dynamic_multiplier' => 'float',
        'calibration_multiplier' => 'float',
        'is_active' => 'boolean',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
        'config_data' => 'array',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
