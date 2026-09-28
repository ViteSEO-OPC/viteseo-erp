<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The number actually dialed, snapshotted onto the call.
 *
 * `crm_calls` had no phone column at all, because a manual call never needed one: the BD read the
 * lead and dialed a handset. A provider-carried call does need one, and re-reading `crm_leads.phone`
 * at display time is not a substitute — a lead's number gets corrected, replaced, or cleared, and the
 * history would then claim the call went somewhere it never went.
 *
 * Nullable, additive, no backfill:
 *
 *   to_number IS NULL      → a manual call (every historical row, and every new manual one). The
 *                            number is not knowable after the fact and is not invented.
 *   to_number IS NOT NULL  → the E.164 number handed to the carrier for this attempt.
 *
 * `crm_leads.phone` is untouched and stays the source field in its original free-text form. This
 * column is a snapshot of one dial, never a normalized copy of the lead, and nothing reads back from
 * it into the lead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_calls', function (Blueprint $table): void {
            $table->string('to_number', 32)->nullable()->after('provider');
        });
    }

    public function down(): void
    {
        Schema::table('crm_calls', function (Blueprint $table): void {
            $table->dropColumn('to_number');
        });
    }
};
