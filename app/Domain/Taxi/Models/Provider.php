<?php

declare(strict_types=1);

namespace App\Domain\Taxi\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Provider extends Model
{
    use HasFactory;

    public const COVERAGE_NATIONWIDE = 'nationwide';

    public const COVERAGE_POSTCODES = 'postcodes';

    public const COVERAGE_TOWNS = 'towns';

    /** Match if pickup town OR postcode is assigned */
    public const COVERAGE_BOTH = 'both';

    protected $fillable = [
        'slug',
        'name',
        'display_name',
        'is_active',
        'coverage_mode',
        'logo_url',
        'booking_url_template',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function pricingConfigs(): HasMany
    {
        return $this->hasMany(ProviderPricingConfig::class);
    }

    public function activePricingConfig(): HasOne
    {
        return $this->hasOne(ProviderPricingConfig::class)
            ->where('is_active', true)
            ->latestOfMany();
    }

    public function postcodes(): HasMany
    {
        return $this->hasMany(ProviderPostcode::class);
    }

    public function towns(): HasMany
    {
        return $this->hasMany(ProviderTown::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ProviderEvent::class);
    }
}
