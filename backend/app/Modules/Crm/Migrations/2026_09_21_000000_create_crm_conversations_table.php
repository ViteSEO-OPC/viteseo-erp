<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inbound conversation threads — the provider-agnostic home for Messenger / Instagram DM /
 * WhatsApp / web-chat / Lead Ads exchanges. One row per provider thread; the individual
 * messages live in `crm_messages`.
 *
 * NOT an extension of `crm_emails`. Email carries envelope semantics (to/subject/RFC message
 * ids) that a chat turn does not have, and a chat thread carries a contact identity that an
 * email thread does not. Keeping them apart leaves the frozen inbound-email pipeline untouched.
 *
 * Domain fields (provider/channel/status) are `string` with backend defaults and are constrained
 * at the FormRequest/adapter layer, mirroring the leads and planner_events convention — the
 * business defaults live in the backend, not the UI.
 *
 * IDENTITY. A conversation is identified by (org, provider, channel, external_conversation_id).
 * `channel` participates because one provider exposes several namespaces: a Meta thread id from
 * Messenger and one from Instagram DM are issued by different systems, and nothing guarantees
 * they can never collide. Scoping by org keeps one tenant's thread from ever resolving another's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_conversations', function (Blueprint $table) {
            $table->id();

            // Tenant. A new table carries org_id inline — the 2026_09_03 backfill only covered
            // the tables that predated tenancy.
            $table->foreignId('org_id')->constrained('orgs')->cascadeOnDelete();

            // Vendor/integration: meta, twilio, web, n8n.
            $table->string('provider', 64);
            // Medium within that vendor: messenger, instagram_dm, whatsapp, web_chat, lead_ads.
            $table->string('channel', 64);

            // The provider's own thread identifier. NOT NULL by contract: a provider without a
            // native thread (a Lead Ads submission, a one-shot web form) must have its adapter
            // synthesize a stable id. The pipeline never invents one, because an id invented
            // per-event would make every replay look like a new conversation.
            $table->string('external_conversation_id', 191);

            // The person on the other side, in the provider's namespace (PSID / IGSID / phone id).
            // Nullable: some channels only reveal it on the first message.
            $table->string('external_contact_id', 191)->nullable();

            // Matched CRM lead, when one is safely identifiable. A conversation deliberately
            // survives without it — an unmatched thread is the chat equivalent of a prospect.
            $table->foreignId('lead_id')->nullable()->constrained('crm_leads')->nullOnDelete();

            // Conversation lifecycle — open, awaiting_agent, closed. DISTINCT from leads.status,
            // which stays a manual BD decision and is never written by this subsystem.
            $table->string('status', 32)->default('open');

            $table->dateTime('started_at')->nullable();
            $table->dateTime('last_message_at')->nullable();

            // Provider CONTEXT (page id, ig account id, ad id, locale) — not the raw payload.
            // The raw event belongs to the message that carried it.
            $table->json('metadata')->nullable();

            $table->timestamps();

            // Thread identity. Named explicitly: the generated name would exceed MySQL's 64-char
            // identifier limit.
            $table->unique(
                ['org_id', 'provider', 'channel', 'external_conversation_id'],
                'crm_conversations_org_provider_channel_ext_unique',
            );

            // Contact identity lookup — the first lead-matching signal after stickiness.
            $table->index(
                ['org_id', 'provider', 'channel', 'external_contact_id'],
                'crm_conversations_org_provider_channel_contact_index',
            );

            // A lead's conversation list, already in recency order.
            $table->index(['org_id', 'lead_id', 'last_message_at'], 'crm_conversations_org_lead_recent_index');

            // Inbox surfaces: open threads, most recent first.
            $table->index(['org_id', 'status', 'last_message_at'], 'crm_conversations_org_status_recent_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_conversations');
    }
};
