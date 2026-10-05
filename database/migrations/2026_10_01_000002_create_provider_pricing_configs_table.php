<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_pricing_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('providers')->cascadeOnDelete();
            $table->decimal('base_fare', 8, 2)->nullable();
            $table->decimal('per_mile_rate', 8, 2)->nullable();
            $table->decimal('per_minute_rate', 8, 2)->nullable();
            $table->decimal('minimum_fare', 8, 2)->nullable();
            $table->decimal('booking_fee', 8, 2)->nullable();
            $table->decimal('airport_fee', 8, 2)->nullable();
            $table->decimal('dynamic_multiplier', 5, 2)->default(1.00);
            $table->string('currency', 3)->default('GBP');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->json('config_data')->nullable();
            $table->timestamps();

            $table->index(['provider_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_pricing_configs');
    }
};
