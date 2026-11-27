<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase C (audit G3): OpenAI-compatible chat jobs. "text" = one input string under the
 * task's system template (the original job API); "chat" = an encrypted list of
 * client messages, still sent after the task's system template. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $t) {
            $t->string('input_kind', 8)->default('text');
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $t) {
            $t->dropColumn('input_kind');
        });
    }
};
