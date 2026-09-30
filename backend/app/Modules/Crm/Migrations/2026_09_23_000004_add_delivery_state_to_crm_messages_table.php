<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives an outbound message somewhere to say whether it has actually been delivered.
 *
 * WHY A COLUMN AND NOT AN EXISTING FIELD. Phase 3F.1 looked for a way to avoid this and found none
 * that was honest:
 *
 *   - `processed_at` already means "the ingest engine finished with this inbound turn". Reusing it
 *     for delivery would give one column two meanings and make every query about either ambiguous.
 *   - `external_message_id IS NULL` cannot tell pending from failed. Both have no provider id, and
 *     those are the two states an operator most needs to distinguish.
 *   - Status inside `payload` JSON is hidden state: unindexable, unconstrained, and invisible to
 *     anyone reading the schema.
 *
 * NULL MEANS INBOUND, and that is the whole backward-compatibility story. An arriving message has
 * no delivery state — it already arrived — so every existing row keeps a null and no backfill is
 * needed. Only rows this system sends carry a value. A default of `pending` would have been wrong
 * in the other direction: it would assert that two real customer messages are waiting to be sent.
 *
 * `failure_code` IS A CODE, NOT A REASON. Bounded, from a closed vocabulary the application owns.
 * Provider error text can quote the message body back at us, exception text can carry query
 * bindings, and either in a database column is a leak that outlives the incident.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_messages', function (Blueprint $table) {
            // pending | sent | failed. Null for every inbound row.
            $table->string('delivery_status', 16)->nullable()->after('direction');

            // A member of OutboundFailureCode, never free text.
            $table->string('failure_code', 32)->nullable()->after('delivery_status');

            // When the provider accepted it. Distinct from occurred_at/received_at, which describe
            // the message, not our attempt to deliver it.
            $table->dateTime('delivered_at')->nullable()->after('failure_code');

            // Finding the queue of things still owed to a provider, per tenant.
            $table->index(['org_id', 'delivery_status'], 'crm_messages_org_delivery_index');
        });
    }

    public function down(): void
    {
        Schema::table('crm_messages', function (Blueprint $table) {
            $table->dropIndex('crm_messages_org_delivery_index');
            $table->dropColumn(['delivery_status', 'failure_code', 'delivered_at']);
        });
    }
};
