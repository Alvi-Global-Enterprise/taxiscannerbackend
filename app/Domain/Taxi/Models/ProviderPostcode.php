<?php

declare(strict_types=1);

namespace App\Domain\Taxi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderPostcode extends Model
{
    protected $fillable = [
        'provider_id',
        'postcode',
        'postcode_outcode',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
