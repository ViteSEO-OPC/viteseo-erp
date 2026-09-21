<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Individual turns inside a `crm_conversations` thread.
 *
 * IDEMPOTENCY is the whole point of this table's key design. A provider webhook is delivered
 * at-least-once, so the same event will arrive again; replaying it must not create a second row
 * and must not re-fire side effects.
 *
 * Two guards, deliberately both:
 *
 *  1. unique(org_id, idempotency_key) — the REAL guarantee. `idempotency_key` is NOT NULL and is
 *     computed by ConversationIdempotency from the provider's message id when it has one, and
 *     from a deterministic hash of the event's stable parts when it does not. Because it is never
 *     null, this key covers every provider, including those that issue no message id at all.
 *
 *  2. unique(org_id, provider, channel, external_message_id) — integrity for providers that DO
 *     issue ids. It cannot stand alone: `external_message_id` is nullable and SQL treats NULLs as
 *     distinct, so a null-id provider would slip past it entirely. `channel` participates for the
 *     same reason it does on the thread: one vendor, several id namespaces.
 *
 * `provider` and `channel` are denormalized onto the row on purpose, so identity can be enforced
 * by a unique index without a join — the same choice `crm_emails` made with its own provider column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('org_id')->constrained('orgs')->cascadeOnDelete();

            // Messages are meaningless without their thread, so they go with it.
            $table->foreignId('conversation_id')->constrained('crm_conversations')->cascadeOnDelete();

            // Denormalized from the conversation — see the class docblock.
            $table->string('provider', 64);
            $table->string('channel', 64);

            // The provider's message id, when it issues one.
            $table->string('external_message_id', 191)->nullable();

            // Never null. Computed, never supplied by the adapter, so one rule covers all providers.
            $table->string('idempotency_key', 64);

            // The delivery envelope that carried this message — kept for replay forensics
            // ("which webhook delivery produced this row?"), not for identity on its own.
            $table->string('external_event_id', 191)->nullable();

            // inbound | outbound. Phase 3A ingests inbound; outbound exists so a future reply path
            // writes into the same thread rather than a parallel table.
            $table->string('direction', 16)->default('inbound');

            $table->string('sender_external_id', 191)->nullable();
            $table->string('recipient_external_id', 191)->nullable();

            // text, image, video, audio, file, template, postback, story_reply, lead_form, system.
            $table->string('message_type', 32)->default('text');

            // Human-readable content. Named `body` to match crm_emails and crm_notes.
            $table->text('body')->nullable();

            // The raw provider event, preserved so nothing is lost in normalization — but the
            // domain model is the columns above, never this blob. It is deliberately NEVER logged.
            $table->json('payload')->nullable();

            // Provider clock vs our clock — the same pairing crm_emails uses (sent_at/received_at).
            $table->dateTime('occurred_at')->nullable();
            $table->dateTime('received_at')->nullable();

            // Set once post-processing has run. A replay finds it already set and does nothing.
            $table->dateTime('processed_at')->nullable();

            $table->timestamps();

            $table->unique(['org_id', 'idempotency_key'], 'crm_messages_org_idempotency_unique');

            $table->unique(
                ['org_id', 'provider', 'channel', 'external_message_id'],
                'crm_messages_org_provider_channel_ext_unique',
            );

            // Thread rendering, in provider-clock order.
            $table->index(['conversation_id', 'occurred_at'], 'crm_messages_conversation_occurred_index');

            // The post-processing sweep: what has landed but not been handled.
            $table->index(['org_id', 'processed_at'], 'crm_messages_org_processed_index');

            // Inbound arrival surfaces.
            $table->index(['org_id', 'direction', 'received_at'], 'crm_messages_org_direction_received_index');

            $table->index(['org_id', 'external_event_id'], 'crm_messages_org_event_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_messages');
    }
};
