<?php

declare(strict_types=1);

namespace App\Domain\Taxi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderEvent extends Model
{
    public const TYPE_COMPARE_IMPRESSION = 'compare_impression';

    public const TYPE_BOOKING_CLICK = 'booking_click';

    protected $fillable = [
        'provider_id',
        'provider_slug',
        'event_type',
        'pickup_postcode',
        'pickup_outcode',
        'client_ip_hash',
        'metadata',
        'occurred_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
