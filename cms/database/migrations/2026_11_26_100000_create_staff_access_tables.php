<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per (user, role). Roles are a fixed enum (App\Enums\Role)
        // mirrored by the gateway, so there is no editable roles table.
        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32);
            $table->timestamps();

            $table->unique(['user_id', 'role']);
            $table->index('role');
        });

        // Assignment of a staff member to a gateway project. The CMS keeps no
        // copy of projects; the gateway project uuid is the reference (ADR D4).
        Schema::create('project_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('gateway_project_uuid');
            $table->string('role', 32);
            $table->timestamps();

            $table->unique(['user_id', 'gateway_project_uuid']);
            $table->index('gateway_project_uuid');
        });

        // Single-use, expiring set-password links. Only a SHA-256 digest of the
        // token is stored.
        Schema::create('invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
        Schema::dropIfExists('project_memberships');
        Schema::dropIfExists('user_roles');
    }
};
