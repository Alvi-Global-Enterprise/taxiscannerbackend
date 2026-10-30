<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_towns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('providers')->cascadeOnDelete();
            $table->string('town_id', 64)->index();
            $table->timestamps();

            $table->unique(['provider_id', 'town_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_towns');
    }
};
