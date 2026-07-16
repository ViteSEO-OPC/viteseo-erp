<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RC3 Phase 1 — Native Scheduled Outbound (Send Later). Additive scheduling + send-lifecycle metadata
 * on the existing `emails` table, plus an append-only `email_events` timeline. No existing column is
 * altered; existing rows keep their `status`. New string-`status` values: scheduled → queued → sending
 * → sent | failed, plus cancelled. `email_events` is analytics-ready: future engagement types
 * (delivered/opened/clicked/bounced/unsubscribed) extend the `type` set with no schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_emails', function (Blueprint $table) {
            $table->timestamp('scheduled_at')->nullable()->after('sent_at');  // when a Send-Later email should go out
            $table->timestamp('queued_at')->nullable()->after('scheduled_at'); // claimed by the sender job
            $table->timestamp('failed_at')->nullable()->after('queued_at');
            $table->timestamp('cancelled_at')->nullable()->after('failed_at');
            $table->unsignedInteger('attempts')->default(0)->after('cancelled_at');

            // The due-scan query path: WHERE status = 'scheduled' AND scheduled_at <= now().
            $table->index(['status', 'scheduled_at']);
        });

        // Append-only per-email lifecycle timeline. RC3 writes send-lifecycle types; Post-RC engagement
        // analytics add new `type` values only — no schema redesign (see KB RC3 §0 FROZEN #8).
        Schema::create('crm_email_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_id')->constrained('crm_emails')->cascadeOnDelete();
            $table->string('type');                 // scheduled|rescheduled|cancelled|queued|sending|sent|failed|…
            $table->timestamp('occurred_at');
            $table->json('meta')->nullable();
            $table->index('email_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_email_events');

        Schema::table('crm_emails', function (Blueprint $table) {
            $table->dropIndex(['status', 'scheduled_at']);
            $table->dropColumn(['scheduled_at', 'queued_at', 'failed_at', 'cancelled_at', 'attempts']);
        });
    }
};
