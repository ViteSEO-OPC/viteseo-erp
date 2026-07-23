<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RC3 Outreach Automation Pack — idempotency key for the automation send path.
 *
 * n8n retries a failed HTTP call up to 5 times. The automation send endpoint had no dedupe, and
 * OutreachEmailService dispatches to the provider BEFORE persisting the row, so a lost/timed-out
 * response could deliver the same customer email up to five times. This column records the CRM→n8n
 * envelope `event_id` that caused the send, so a retry is recognised and answered from history.
 *
 * Additive and backfill-free: existing rows stay NULL, and NULLs are exempt from the unique index, so
 * the frozen user-facing composer (which never sets this) is unaffected.
 *
 * The unique pair is (automation_event_id, purpose) rather than the id alone: one domain event may
 * legitimately drive more than one distinct automated purpose, but never the same purpose twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_emails', function (Blueprint $table): void {
            $table->uuid('automation_event_id')->nullable()->after('purpose');
            $table->unique(['automation_event_id', 'purpose'], 'crm_emails_automation_event_purpose_unique');
        });
    }

    public function down(): void
    {
        Schema::table('crm_emails', function (Blueprint $table): void {
            $table->dropUnique('crm_emails_automation_event_purpose_unique');
            $table->dropColumn('automation_event_id');
        });
    }
};
