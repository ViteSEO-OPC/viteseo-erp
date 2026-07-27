<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-Channel Internal Notifications — append-only delivery history for INTERNAL notifications
 * raised by an inbound customer reply. Deliberately mirrors `crm_reminder_deliveries` (RC3 Phase 3):
 * delivery metadata belongs to the DELIVERY subsystem, NOT to `crm_emails`, which stays the record of
 * customer communication only.
 *
 * `unique(email_id, channel)` is the idempotency claim — the dispatcher firstOrCreate's on it, so
 * overlapping fan-outs or a redelivered Pub/Sub push can never double-notify a CRM user. Extending to
 * slack/teams/sms/whatsapp adds `channel` values only: no schema change, and no change to the inbound
 * business layer.
 *
 * The in-app database notification is NOT recorded here — it predates this table, is always-on, and is
 * already idempotent via the once-only fan-out in InboundEmailService::ingest(). This table tracks the
 * channels the dispatcher owns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_notification_deliveries', function (Blueprint $table) {
            $table->id();
            // The inbound email that triggered the notification.
            $table->foreignId('email_id')->constrained('crm_emails')->cascadeOnDelete();
            $table->string('channel');           // mail (today) | slack | teams | sms | whatsapp (future)
            $table->timestamp('delivered_at');
            $table->json('meta')->nullable();    // notifiable_id / recipient / channel-specific detail

            // Idempotency: one delivery per (inbound email, channel).
            $table->unique(['email_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_notification_deliveries');
    }
};
