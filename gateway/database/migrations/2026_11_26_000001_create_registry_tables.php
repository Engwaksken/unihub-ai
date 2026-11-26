<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 120);
            $t->string('status', 16)->default('active'); // active|archived
            $t->timestamps();
        });

        Schema::create('projects', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->string('slug', 63)->unique();
            $t->string('name', 120);
            $t->string('owner_ref', 120)->nullable();
            $t->string('contact_email', 190)->nullable();
            $t->string('environment', 16); // staging|production
            $t->string('status', 16)->default('active'); // active|disabled|archived
            $t->unsignedInteger('daily_limit');
            $t->unsignedInteger('rpm_limit');
            $t->unsignedInteger('queue_limit');
            $t->timestamp('last_served_at', 6)->nullable(); // round-robin fairness
            $t->timestamps();
            $t->index('status');
        });

        Schema::create('api_keys', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $t->string('label', 80)->nullable();
            $t->string('environment', 8); // live|test
            $t->string('prefix', 16)->unique();
            $t->string('digest', 64); // hex HMAC-SHA256(pepper, full key)
            $t->uuid('rotated_from_id')->nullable();
            $t->string('created_by', 120);
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();
            $t->index('project_id');
        });

        Schema::create('models', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('tag', 120)->unique();
            $t->string('digest', 80)->nullable();
            $t->string('licence', 120)->nullable();
            $t->unsignedBigInteger('size_bytes')->nullable();
            $t->boolean('installed')->default(false);
            $t->boolean('enabled')->default(false);
            $t->timestamp('checked_at')->nullable();
            $t->timestamps();
        });

        Schema::create('tasks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('key', 64)->unique();
            $t->string('description', 500)->nullable();
            $t->timestamps();
        });

        Schema::create('task_versions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('task_id')->constrained('tasks')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->text('system_template');
            $t->unsignedSmallInteger('output_cap');
            $t->string('output_format', 8)->default('text'); // text|json
            $t->foreignUuid('model_id')->constrained('models')->restrictOnDelete();
            $t->timestamp('published_at')->nullable();
            $t->string('created_by', 120);
            $t->timestamps();
            $t->unique(['task_id', 'version']);
        });

        Schema::create('project_tasks', function (Blueprint $t) {
            $t->foreignUuid('project_id')->constrained('projects')->cascadeOnDelete();
            $t->foreignUuid('task_id')->constrained('tasks')->restrictOnDelete();
            $t->primary(['project_id', 'task_id']);
        });

        Schema::create('settings', function (Blueprint $t) {
            $t->string('key', 64)->primary();
            $t->text('value'); // JSON
            $t->string('updated_by', 120)->nullable();
            $t->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['settings', 'project_tasks', 'task_versions', 'tasks', 'models', 'api_keys', 'projects', 'organizations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
