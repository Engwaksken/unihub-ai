<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('user_id')->index(); // adapt to the app's user key type
            $t->string('task', 64);
            $t->text('input');                           // encrypted cast; delete after completion per retention policy
            $t->unsignedSmallInteger('max_tokens')->default(200);
            $t->uuid('idempotency_key')->unique();
            $t->uuid('gateway_job_id')->nullable()->unique();
            $t->string('status', 16)->index();
            $t->text('output')->nullable();              // encrypted cast
            $t->string('error_code', 40)->nullable();
            $t->timestamp('next_poll_at')->nullable();
            $t->timestamp('retry_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_requests');
    }
};
