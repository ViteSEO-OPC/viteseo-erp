<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One tenant's authorization with one provider — the thing an operator connects and disconnects.
 *
 * WHY THIS IS A SEPARATE TABLE FROM `crm_provider_accounts`. That table answers "whose is this
 * Page?" for a public webhook and must stay answerable with no credential, no session and no
 * expiry check. This one answers "is this tenant still authorized, and until when?", which is a
 * question with a lifecycle: it goes stale, it gets revoked, it expires. Folding the two together
 * would make every webhook ownership lookup read a row that changes for reasons the webhook does
 * not care about — and would couple inbound delivery to the health of an outbound grant.
 *
 * INBOUND MUST NEVER DEPEND ON THIS ROW. A Page whose token expired still receives messages, and
 * refusing to ingest them because the tenant cannot currently reply would lose real customer
 * traffic to a billing problem. Nothing in the inbound path reads this table.
 *
 * THE UNIQUE KEY IS GLOBAL, NOT ORG-SCOPED, for the same reason the resource table's is: a Meta
 * Business belongs to one customer, and two tenants each claiming it is a provisioning mistake that
 * should be impossible rather than resolved at runtime. `external_business_id` is nullable because
 * a connection exists before OAuth has told us the business id — and MariaDB treats NULLs as
 * distinct in a unique index, so several un-identified connections per org are permitted. That is
 * deliberate: a half-finished connect attempt must not block a retry.
 *
 * NO CREDENTIAL COLUMN. Secrets live in `crm_provider_connection_credentials`, following the
 * precedent this codebase already set with `crm_mailbox_oauth_tokens`.
 *
 * DISCONNECTION IS A STATUS, NOT A DELETE. Historical conversations point at resources that point
 * at connections; erasing one would erase the provenance of every thread that arrived through it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_provider_connections', function (Blueprint $table) {
            $table->id();

            $table->foreignId('org_id')->constrained('orgs')->cascadeOnDelete();

            // `meta` today. Matches the vocabulary in config/inbound.php.
            $table->string('provider', 64);

            /*
             * The smallest state model that can express what an operator needs to see:
             *
             *   connected    — authorized; resources under it may be used for new outbound sends
             *   disconnected — revoked or deliberately unlinked; blocks NEW sends, keeps history
             *   error        — the provider rejected our credential; needs a human, not a retry
             *
             * A boolean would collapse the last two, and "why did sending stop?" is exactly the
             * question an operator opens the page to answer.
             */
            $table->string('status', 32)->default('connected');

            // The provider's own id for the business/account that granted access. Nullable until
            // the connect flow (Phase 3F.3) learns it.
            $table->string('external_business_id', 191)->nullable();

            // Who clicked connect. Nullable and null-on-delete: a departed employee's connection
            // must keep working, and must keep saying that the actor is no longer known.
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();

            $table->dateTime('connected_at')->nullable();
            $table->dateTime('disconnected_at')->nullable();

            // When the grant behind this connection stops working, if the provider says. Read by a
            // future expiry warning job; never used to decide inbound behaviour.
            $table->dateTime('token_expires_at')->nullable();

            // Non-secret provider detail: granted scopes, business name for display. A secret here
            // would defeat the whole point of the separate credential table.
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->unique(['provider', 'external_business_id'], 'crm_provider_connections_business_unique');
            $table->index(['org_id', 'provider'], 'crm_provider_connections_org_provider_index');
            $table->index(['status', 'token_expires_at'], 'crm_provider_connections_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_provider_connections');
    }
};
