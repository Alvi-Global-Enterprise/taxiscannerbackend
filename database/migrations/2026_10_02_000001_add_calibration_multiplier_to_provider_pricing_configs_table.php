<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_pricing_configs', function (Blueprint $table) {
            $table->decimal('calibration_multiplier', 6, 4)->default(1.0000)->after('dynamic_multiplier');
        });
    }

    public function down(): void
    {
        Schema::table('provider_pricing_configs', function (Blueprint $table) {
            $table->dropColumn('calibration_multiplier');
        });
    }
};
