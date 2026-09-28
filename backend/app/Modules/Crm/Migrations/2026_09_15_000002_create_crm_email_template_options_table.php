<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each organization's own email-template purposes and tones, added to the built-in catalog.
 *
 * Templates keep storing purpose and tone as plain strings (crm_email_templates.purpose / .tone), exactly as they
 * always have: the Outreach composer, the automation resolver and every sent email read those strings. This table is
 * only the list of custom values an organization may choose from, so a value is defined once and reused.
 *
 * `normalized_value` is the value lower-cased with spaces, underscores and hyphens treated alike. The unique index
 * covers soft-deleted rows too: re-adding a deleted value restores its row (EmailTemplateOptionService), so a deleted
 * row never blocks it and two live rows can never share a value, however spelled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_email_template_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id')->constrained('orgs')->cascadeOnDelete();
            $table->string('type', 10);
            $table->string('value', 60);
            $table->string('normalized_value', 120);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['org_id', 'type', 'normalized_value'], 'crm_email_template_options_org_type_value_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_email_template_options');
    }
};
