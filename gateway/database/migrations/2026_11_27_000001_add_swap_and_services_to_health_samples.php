<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Audit G14: swap usage and per-service status on each telemetry sample. Additive only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('health_samples', function (Blueprint $t) {
            $t->unsignedInteger('swap_total_mb')->nullable();
            $t->unsignedInteger('swap_used_mb')->nullable();
            $t->text('services')->nullable(); // JSON [{name, status}]
        });
    }

    public function down(): void
    {
        Schema::table('health_samples', function (Blueprint $t) {
            $t->dropColumn(['swap_total_mb', 'swap_used_mb', 'services']);
        });
    }
};
