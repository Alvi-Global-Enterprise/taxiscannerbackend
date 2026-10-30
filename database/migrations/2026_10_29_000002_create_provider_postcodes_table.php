<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_postcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('providers')->cascadeOnDelete();
            // Stored normalised: uppercase, no spaces. Prefer UK outward code (e.g. M1, SW1A).
            $table->string('postcode', 16);
            $table->string('postcode_outcode', 8)->index();
            $table->timestamps();

            $table->unique(['provider_id', 'postcode_outcode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_postcodes');
    }
};
