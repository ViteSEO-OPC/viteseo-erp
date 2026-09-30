<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One METADATA row per Copilot AI request — what ran and how it ended, never what was said.
 *
 * Deliberately absent: the question text (only its SHA-256 and length), the CRM context sent to the
 * model, the model's answer, and any credential. Enough to measure use, cost and failure modes and to
 * investigate a report, without becoming a second store of CRM data or of what users typed.
 *
 * Org-scoped like every tenant table; pruned after `copilot.ai.log_retention_days`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_copilot_ai_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->nullable()->constrained('orgs')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->char('question_hash', 64);
            $table->unsignedSmallInteger('question_length');
            // {"lead_portfolio": 436, "knowledge": 3, ...} — capability key => records supplied.
            $table->json('capabilities')->nullable();
            $table->string('model', 120)->nullable();
            $table->string('instruction_version', 32);
            $table->unsignedInteger('duration_ms');
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            // answered | declined | ungrounded | unsafe | provider_error | timeout | daily_limit | circuit_open | unavailable
            $table->string('outcome', 32);
            // The validator's reason code when the outcome is a rejection; never free text.
            $table->string('reason', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['org_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_copilot_ai_logs');
    }
};
