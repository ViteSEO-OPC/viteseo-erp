<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-organization configuration for the OPTIONAL paid call transport.
 *
 * ── Why this table exists rather than `.env` ──
 * Browser calling is a capability a ViteSEO client buys from Twilio with their own account and their
 * own money. A credential that can only be installed by editing a file on the server is not something
 * a client's admin can ever own, and it cannot differ between two orgs on one deployment. This is the
 * store that makes it a settings screen instead.
 *
 * ── ORG-SCOPED, and that is the whole difference from `crm_nerby_provider_settings` ──
 * The Nearby settings table is deliberately global, because the discovery API keys it holds are
 * ViteSEO's own, bought centrally and shared by every tenant — which is why its routes are super-admin
 * only. Its route comment states the rule for the opposite case exactly: "If a client is ever meant to
 * bring their own key, this is the wrong fix: the table needs org_id and this group goes back to
 * crm_admin." A Twilio account IS the client's own, so this table carries `org_id` and its routes are
 * `crm_admin`. One org's credential can never be read, replaced or spent by another.
 *
 * ── One row per (org, provider) ──
 * Same shape as the Nearby table and for the same reason: adding a second paid transport later
 * (Teams, RingCentral, Aircall — all named in the `crm_calls` migration) is a new row, not a
 * migration. `provider` is a varchar rather than an enum column so that stays true.
 *
 * NO ROWS IS A VALID STATE, AND IT IS THE DEFAULT. An empty table means every org runs the free manual
 * transport, which is exactly today's behaviour. Nothing is seeded, and a fresh install needs nothing.
 *
 * ── Which columns are secret, and which only look it ──
 * `auth_token` and `api_secret` are ENCRYPTED at rest by the model casts (Laravel APP_KEY) and are the
 * only two values that can place calls or forge a webhook signature. They are never returned by any
 * endpoint, never logged, and never sent to a browser.
 *
 * The rest are IDENTIFIERS, stored plainly on purpose: `account_sid`, `api_key` (the SK… sid),
 * `twiml_app_sid`, `caller_id` and `webhook_base_url` are values Twilio itself shows in a console and
 * puts in webhook payloads, and an admin has to be able to SEE them to confirm which account is
 * wired up. Encrypting them would buy nothing and cost the one thing the settings page needs.
 *
 * `*_last4` columns exist for the two encrypted values because ciphertext cannot be displayed or
 * compared — each row carries a distinct IV, so an encrypted column can never be matched with a WHERE
 * clause either. The same trap is documented on {@see \App\Modules\Crm\Models\MailboxOauthToken}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_call_provider_settings', function (Blueprint $table): void {
            $table->id();

            // The owning tenant. Every read and write goes through BelongsToOrg, so one org's
            // credentials are unreachable from another's session.
            $table->foreignId('org_id')->constrained('orgs')->cascadeOnDelete();

            // The transport this row configures ('twilio'). Plain and indexed precisely because the
            // credential columns beside it are neither.
            $table->string('provider');

            // Whether this org has switched the paid transport ON. Separate from "is it complete":
            // an admin can keep a full configuration stored while switched off, and turn it back on
            // without re-entering a secret.
            $table->boolean('enabled')->default(false);

            // ── Identifiers: plain, displayable ──
            $table->string('account_sid')->nullable();
            $table->string('api_key')->nullable();
            $table->string('twiml_app_sid')->nullable();
            $table->string('caller_id', 32)->nullable();
            $table->string('webhook_base_url')->nullable();

            // ── Secrets: ciphertext at rest, never in a response ──
            $table->text('auth_token')->nullable();
            $table->text('api_secret')->nullable();
            $table->string('auth_token_last4', 8)->nullable();
            $table->string('api_secret_last4', 8)->nullable();

            // Outcome of the last explicit connection test.
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status')->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One configuration per provider per org.
            $table->unique(['org_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_call_provider_settings');
    }
};
