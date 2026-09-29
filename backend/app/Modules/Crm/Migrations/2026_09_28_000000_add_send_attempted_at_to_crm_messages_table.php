<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3H.5A — record the moment an outbound message was handed to the provider.
 *
 * WHY A COLUMN IS NEEDED. Before this, a row that was `pending` with a NULL failure code meant two
 * different things that nothing could tell apart: "created, not yet sent" and "sent to Meta, but the
 * request died before the outcome was written". A replay of the same client request id treated
 * both as the first and called Meta again — a second message to a real customer. This column is
 * what lets the send path CLAIM a row atomically before the provider call, so a second attempt sees
 * the claim and stops, and what lets a stranded attempt be recognised as "outcome unknown" instead
 * of looking like a harmless "pending" forever.
 *
 * ADDITIVE, NULLABLE, NO BACKFILL. Existing rows keep NULL, and NULL keeps its old meaning for them:
 * no recorded attempt. Historical outcomes (`sent`, `failed`, and any legacy `pending`) are left
 * exactly as they are; the read-side rules treat an old unclaimed `pending` row by its creation
 * time, never by inventing an attempt time for it.
 *
 * No index: every reader reaches these rows through the existing (org_id, idempotency_key) unique
 * index or through a conversation's own messages, never by scanning on this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_messages', function (Blueprint $table) {
            $table->dateTime('send_attempted_at')->nullable()->after('delivered_at');
        });
    }

    public function down(): void
    {
        Schema::table('crm_messages', function (Blueprint $table) {
            $table->dropColumn('send_attempted_at');
        });
    }
};
