<?php

declare(strict_types=1);

namespace App\Domain\Taxi\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FareEstimate extends Model
{
    use HasFactory;

    protected $fillable = [
        'route_search_id',
        'provider_slug',
        'quote_type',
        'min_price',
        'max_price',
        'currency',
        'is_available',
        'error_message',
        'metadata',
    ];

    protected $casts = [
        'min_price' => 'float',
        'max_price' => 'float',
        'is_available' => 'boolean',
        'metadata' => 'array',
    ];

    public function routeSearch(): BelongsTo
    {
        return $this->belongsTo(RouteSearch::class);
    }
}
