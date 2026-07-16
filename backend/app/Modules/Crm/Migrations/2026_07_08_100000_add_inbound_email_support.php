<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive support for inbound email synchronization (Sprint 2). Extends the existing
 * `emails` table to hold both directions + RFC threading headers, and adds a
 * provider-neutral `mailbox_syncs` cursor store (Gmail first; Microsoft Graph / IMAP later).
 * No existing column is altered destructively; existing rows default to `direction=outbound`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_emails', function (Blueprint $table) {
            // Distinguishes the two flows in one table (reuse, per requirement). Existing rows → outbound.
            $table->string('direction')->default('outbound')->after('id');
            // Inbound sender (outbound backfills these from the configured From identity).
            $table->string('from_email')->nullable()->after('user_id');
            $table->string('from_name')->nullable()->after('from_email');
            // RFC 5322 threading headers.
            $table->string('message_id')->nullable()->after('provider_message_id'); // this email's Message-ID (bare, no <>)
            $table->string('in_reply_to')->nullable()->after('message_id');          // Message-ID this replies to
            $table->text('references')->nullable()->after('in_reply_to');            // full References chain
            $table->string('thread_id')->nullable()->after('references');           // provider-native / derived thread key
            $table->timestamp('received_at')->nullable()->after('sent_at');          // inbound receipt time
            $table->json('raw_headers')->nullable()->after('received_at');           // minimal header capture (audit / auto-reply + bounce detection)

            $table->index('message_id');
            $table->index('in_reply_to');
            $table->index('thread_id');
            // Idempotency for at-least-once delivery: the same email can never persist twice.
            // (MySQL treats NULLs as distinct, so legacy null-message_id rows do not collide.)
            $table->unique(['provider', 'message_id']);
        });

        // Provider-neutral mailbox sync state. `sync_cursor` holds whatever the provider needs:
        // Gmail historyId, Microsoft Graph deltaLink, or IMAP UIDVALIDITY:UID.
        Schema::create('crm_mailbox_syncs', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();            // the synchronized mailbox
            $table->string('provider');                    // gmail | graph | imap
            $table->string('sync_cursor')->nullable();     // provider-neutral cursor
            $table->timestamp('watch_expires_at')->nullable(); // push subscription/watch expiry (null for pull providers)
            $table->timestamp('last_synced_at')->nullable();
            $table->string('status')->default('active');   // active | paused | error
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_mailbox_syncs');

        Schema::table('crm_emails', function (Blueprint $table) {
            $table->dropUnique(['provider', 'message_id']);
            $table->dropIndex(['message_id']);
            $table->dropIndex(['in_reply_to']);
            $table->dropIndex(['thread_id']);
            $table->dropColumn([
                'direction', 'from_email', 'from_name', 'message_id',
                'in_reply_to', 'references', 'thread_id', 'received_at', 'raw_headers',
            ]);
        });
    }
};
