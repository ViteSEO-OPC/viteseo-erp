<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records which of the tenant's connected resources a thread actually arrived on.
 *
 * WHY THIS COLUMN EXISTS AT ALL. Phase 3F.1 could only infer the sender for a reply, by reading the
 * newest inbound row's `recipient_external_id` and falling back to "the org's only Page". That
 * fallback refuses the moment a SaaS tenant connects a second Page — correctly, because guessing
 * would deliver a reply to a real customer from the wrong business — but refusing is not a product.
 * Recording the answer once, at ingest, when the signature-verified payload still says it, removes
 * the guess entirely.
 *
 * NULLABLE FOR HISTORY, NOT FOR CONVENIENCE. The two conversations already in production were
 * created before this column existed. They keep a null and continue to resolve through the Phase
 * 3F.1 inference path, which still works for a single-resource org. NO BACKFILL IS PERFORMED HERE:
 * the deterministic rule exists — their newest inbound row names the Page — but running it as part
 * of a schema migration would mean a data write nobody reviewed as one.
 *
 * WRITTEN ONCE, ON CREATE, NEVER ON REPLAY. A conversation's resource is a fact about where the
 * thread lives, not about the latest message; rewriting it would let a misrouted delivery move an
 * existing thread to another Page.
 *
 * NULL ON DELETE, so a removed mapping degrades the thread to "resource unknown" rather than
 * deleting the customer's conversation. The application must not delete referenced resources at
 * all — this is the safety net under that rule, not a substitute for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_conversations', function (Blueprint $table) {
            $table->foreignId('provider_account_id')
                ->nullable()
                ->after('channel')
                ->constrained('crm_provider_accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crm_conversations', function (Blueprint $table) {
            $table->dropForeign(['provider_account_id']);
            $table->dropColumn('provider_account_id');
        });
    }
};
