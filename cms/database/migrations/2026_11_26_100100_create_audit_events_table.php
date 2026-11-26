<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only CMS audit trail. The model refuses updates and deletes and
        // the application exposes no route that changes rows. There is no
        // foreign key to users, so deleting a user can never cascade into audit.
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at', 6)->index();
            $table->uuid('request_id')->nullable()->index();
            $table->uuid('actor_user_id')->nullable()->index();
            $table->string('actor_label')->nullable();
            $table->string('action', 64)->index();
            $table->string('subject_type', 32)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->uuid('gateway_project_uuid')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('metadata')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
