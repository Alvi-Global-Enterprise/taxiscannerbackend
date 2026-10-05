<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fare_estimates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_search_id')->nullable()->constrained('route_searches')->nullOnDelete();
            $table->string('provider_slug')->index();
            $table->string('quote_type')->default('estimate');
            $table->decimal('min_price', 8, 2)->nullable();
            $table->decimal('max_price', 8, 2)->nullable();
            $table->string('currency', 3)->default('GBP');
            $table->boolean('is_available')->default(true);
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['provider_slug', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fare_estimates');
    }
};
