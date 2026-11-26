<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Non-human accounts (the alert notifier). They can never sign in.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('password_set_at');
        });

        // CMS-only settings (ADR D4): branding name, display time zone,
        // notification recipients. Gateway policy settings live in the gateway.
        Schema::create('cms_settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->json('value');
            $table->uuid('updated_by')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        // One e-mail per gateway alert id (dedupe for the notifier).
        Schema::create('alert_notifications', function (Blueprint $table) {
            $table->string('alert_key', 80)->primary(); // gateway alert uuid, or "local:<type>:<since>"
            $table->string('alert_type', 64);
            $table->string('severity', 16)->nullable();
            $table->unsignedInteger('recipient_count');
            $table->timestamp('notified_at');
        });

        // Small key/value store for scheduler state (e.g. gateway reachability streak).
        Schema::create('system_state', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->json('value');
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_state');
        Schema::dropIfExists('alert_notifications');
        Schema::dropIfExists('cms_settings');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_system'));
    }
};
