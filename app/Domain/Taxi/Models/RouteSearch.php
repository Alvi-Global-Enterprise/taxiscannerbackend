<?php

declare(strict_types=1);

namespace App\Domain\Taxi\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RouteSearch extends Model
{
    use HasFactory;

    protected $fillable = [
        'pickup_query',
        'dropoff_query',
        'pickup_formatted',
        'dropoff_formatted',
        'pickup_latitude',
        'pickup_longitude',
        'dropoff_latitude',
        'dropoff_longitude',
        'distance_miles',
        'duration_minutes',
        'client_ip_hash',
    ];

    protected $casts = [
        'pickup_latitude' => 'float',
        'pickup_longitude' => 'float',
        'dropoff_latitude' => 'float',
        'dropoff_longitude' => 'float',
        'distance_miles' => 'float',
        'duration_minutes' => 'integer',
    ];

    public function fareEstimates(): HasMany
    {
        return $this->hasMany(FareEstimate::class);
    }
}
