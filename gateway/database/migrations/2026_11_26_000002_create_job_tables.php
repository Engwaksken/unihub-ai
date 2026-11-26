<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jobs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $t->uuid('api_key_id')->nullable();
            $t->foreignUuid('task_version_id')->constrained('task_versions')->restrictOnDelete();
            $t->foreignUuid('model_id')->constrained('models')->restrictOnDelete();
            $t->string('state', 16); // queued|running|completed|failed|cancelled|expired
            $t->string('idem_key', 128);
            $t->char('input_hash', 64);
            $t->unsignedSmallInteger('max_tokens');
            $t->unsignedInteger('est_input_tokens');
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('available_at', 6);             // claimable from (retry backoff)
            $t->timestamp('queued_at', 6);
            $t->timestamp('queue_deadline_at', 6);
            $t->timestamp('lease_until', 6)->nullable();
            $t->string('worker_id', 64)->nullable();
            $t->timestamp('started_at', 6)->nullable();
            $t->timestamp('finished_at', 6)->nullable();
            $t->timestamp('expires_at', 6)->nullable();   // payload/result purge time
            $t->string('error_code', 40)->nullable();
            $t->string('error_message', 255)->nullable();  // safe, generic text only
            $t->boolean('error_transient')->default(false);
            $t->unsignedInteger('tokens_in')->nullable();
            $t->unsignedInteger('tokens_out')->nullable();
            $t->uuid('retry_of')->nullable();
            $t->uuid('request_id');
            $t->timestamps(6);

            $t->unique(['project_id', 'idem_key']);
            $t->index(['state', 'created_at']);
            $t->index(['project_id', 'created_at']);
            $t->index('lease_until');
            $t->index('expires_at');
        });

        // Encrypted payloads kept apart from operational metadata (PRD §05, ADR D10).
        Schema::create('job_payloads', function (Blueprint $t) {
            $t->uuid('job_id')->primary();
            $t->foreign('job_id')->references('id')->on('jobs')->cascadeOnDelete();
            $t->string('key_id', 32);
            $t->binary('input_ct');
            $t->binary('output_ct')->nullable();
            $t->timestamp('created_at', 6);
        });

        // No FK on job_id: usage must survive job-metadata purge ordering; uniqueness prevents duplicates.
        Schema::create('usage_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('job_id')->unique();
            $t->uuid('project_id');
            $t->uuid('model_id');
            $t->string('task_key', 64);
            $t->string('status', 16); // completed|failed
            $t->string('error_code', 40)->nullable();
            $t->unsignedInteger('tokens_in')->nullable();
            $t->unsignedInteger('tokens_out')->nullable();
            $t->unsignedInteger('queue_ms');
            $t->unsignedInteger('inference_ms');
            $t->unsignedInteger('total_ms');
            $t->timestamp('created_at', 6);
            $t->index(['project_id', 'created_at']);
            $t->index('created_at');
        });

        Schema::create('quota_counters', function (Blueprint $t) {
            $t->uuid('project_id');
            $t->string('window_type', 8); // minute|day
            $t->string('window_start', 20); // UTC 'Y-m-d H:i:00' or 'Y-m-d'
            $t->unsignedInteger('count')->default(0);
            $t->primary(['project_id', 'window_type', 'window_start']);
        });
    }

    public function down(): void
    {
        foreach (['quota_counters', 'usage_events', 'job_payloads', 'jobs'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
