<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('actor_ref', 160);     // cms:<user uuid> | system:<component> | key:<prefix>
            $t->string('actor_name', 120)->nullable();
            $t->string('action', 64);
            $t->string('target_type', 32);
            $t->string('target_id', 64)->nullable();
            $t->uuid('project_id')->nullable();
            $t->text('metadata')->nullable(); // JSON; safe field diffs only, never secrets/payloads
            $t->uuid('request_id')->nullable();
            $t->timestamp('created_at', 6);
            $t->index(['project_id', 'created_at']);
            $t->index('created_at');
            $t->index('action');
        });

        // Immutable audit trail (threat model T13).
        DB::unprepared("CREATE TRIGGER audit_events_no_update BEFORE UPDATE ON audit_events BEGIN SELECT RAISE(ABORT, 'audit_events is append-only'); END;");
        DB::unprepared("CREATE TRIGGER audit_events_no_delete BEFORE DELETE ON audit_events BEGIN SELECT RAISE(ABORT, 'audit_events is append-only'); END;");

        Schema::create('health_samples', function (Blueprint $t) {
            $t->id();
            $t->string('host_id', 64);
            $t->timestamp('sampled_at', 6);
            $t->float('cpu_pct')->nullable();
            $t->float('ram_used_pct')->nullable();
            $t->unsignedInteger('ram_total_mb')->nullable();
            $t->unsignedInteger('ram_used_mb')->nullable();
            $t->float('disk_used_pct')->nullable();
            $t->unsignedInteger('queue_depth');
            $t->unsignedInteger('running');
            $t->timestamp('worker_heartbeat_at', 6)->nullable();
            $t->string('ollama_status', 16); // loaded|idle|unreachable|unknown
            $t->string('loaded_model', 120)->nullable();
            $t->timestamp('tls_expires_at')->nullable();
            $t->index('sampled_at');
        });

        Schema::create('worker_heartbeats', function (Blueprint $t) {
            $t->string('worker_id', 64)->primary();
            $t->unsignedInteger('pid');
            $t->uuid('current_job_id')->nullable();
            $t->timestamp('started_at', 6);
            $t->timestamp('heartbeat_at', 6);
        });

        Schema::create('alerts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type', 40);
            $t->string('severity', 16); // info|warning|critical
            $t->string('status', 16);   // open|acknowledged|resolved
            $t->string('message', 255);
            $t->timestamp('first_seen_at', 6);
            $t->timestamp('last_seen_at', 6);
            $t->string('acked_by', 160)->nullable();
            $t->timestamp('acked_at', 6)->nullable();
            $t->timestamp('resolved_at', 6)->nullable();
            $t->timestamps(6);
            $t->index(['type', 'status']);
        });

        Schema::create('assertion_nonces', function (Blueprint $t) {
            $t->string('jti', 64)->primary();
            $t->timestamp('expires_at');
        });

        Schema::create('mgmt_idempotency', function (Blueprint $t) {
            $t->string('key', 128);
            $t->string('actor_ref', 160);
            $t->char('request_hash', 64);
            $t->unsignedSmallInteger('status');
            $t->text('body')->nullable(); // null => not replayable (e.g. key issuance)
            $t->timestamp('created_at');
            $t->primary(['key', 'actor_ref']);
        });
    }

    public function down(): void
    {
        foreach (['mgmt_idempotency', 'assertion_nonces', 'alerts', 'worker_heartbeats', 'health_samples', 'audit_events'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
