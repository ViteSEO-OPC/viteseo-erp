<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RC3 Phase 2 — Deterministic Template Engine. Database-backed email templates keyed by Purpose + Tone,
 * each with a Subject + Body variant containing deterministic `{{token}}` placeholders (rendered by
 * TemplateRenderer from a whitelist — no AI, no Blade). First-class asset: active/inactive + ownership.
 * One active template per (purpose, tone). Additive; touches nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('purpose');                 // e.g. "Proposal Check-in" (the composer's EMAIL PURPOSE)
            $table->string('tone');                     // e.g. "Warm & professional" (the composer's TONE)
            $table->string('subject');                  // Subject variant, with {{tokens}}
            $table->text('body');                       // Body variant, with {{tokens}}
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); // owner
            $table->timestamps();
            $table->softDeletes();

            $table->index(['purpose', 'tone', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_email_templates');
    }
};
