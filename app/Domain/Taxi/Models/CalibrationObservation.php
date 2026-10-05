<?php

declare(strict_types=1);

namespace App\Domain\Taxi\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalibrationObservation extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_id',
        'provider_slug',
        'pickup',
        'dropoff',
        'route_distance',
        'route_duration',
        'observed_real_world_fare',
        'estimated_fare',
        'difference_percentage',
        'recommended_multiplier',
        'observed_at',
        'trip_category',
        'notes',
        'is_outlier',
        'metadata',
    ];

    protected $casts = [
        'route_distance' => 'float',
        'route_duration' => 'integer',
        'observed_real_world_fare' => 'float',
        'estimated_fare' => 'float',
        'difference_percentage' => 'float',
        'recommended_multiplier' => 'float',
        'observed_at' => 'datetime',
        'is_outlier' => 'boolean',
        'metadata' => 'array',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function scopeForProvider(Builder $query, string $providerSlug): Builder
    {
        return $query->where('provider_slug', strtolower($providerSlug));
    }

    public function scopeInliers(Builder $query): Builder
    {
        return $query->where('is_outlier', false);
    }
}
