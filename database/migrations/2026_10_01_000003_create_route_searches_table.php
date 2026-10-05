<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_searches', function (Blueprint $table) {
            $table->id();
            $table->string('pickup_query');
            $table->string('dropoff_query');
            $table->string('pickup_formatted')->nullable();
            $table->string('dropoff_formatted')->nullable();
            $table->decimal('pickup_latitude', 10, 7)->nullable();
            $table->decimal('pickup_longitude', 10, 7)->nullable();
            $table->decimal('dropoff_latitude', 10, 7)->nullable();
            $table->decimal('dropoff_longitude', 10, 7)->nullable();
            $table->decimal('distance_miles', 8, 2)->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->string('client_ip_hash', 64)->nullable()->index();
            $table->timestamps();

            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_searches');
    }
};
