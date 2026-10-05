<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calibration_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('providers')->cascadeOnDelete();
            $table->string('provider_slug', 32)->index();
            $table->string('pickup');
            $table->string('dropoff');
            $table->decimal('route_distance', 8, 2); // miles
            $table->integer('route_duration'); // minutes
            $table->decimal('observed_real_world_fare', 8, 2);
            $table->decimal('estimated_fare', 8, 2);
            $table->decimal('difference_percentage', 8, 2);
            $table->decimal('recommended_multiplier', 6, 4);
            $table->timestamp('observed_at')->useCurrent()->index();
            $table->string('trip_category', 64)->nullable()->index();
            $table->text('notes')->nullable();
            $table->boolean('is_outlier')->default(false)->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['provider_slug', 'trip_category']);
            $table->index(['provider_slug', 'observed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calibration_observations');
    }
};
