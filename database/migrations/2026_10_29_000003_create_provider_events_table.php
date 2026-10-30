<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->nullable()->constrained('providers')->nullOnDelete();
            $table->string('provider_slug', 64)->index();
            // compare_impression | booking_click
            $table->string('event_type', 64)->index();
            $table->string('pickup_postcode', 16)->nullable()->index();
            $table->string('pickup_outcode', 8)->nullable()->index();
            $table->string('client_ip_hash', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->useCurrent()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_events');
    }
};
