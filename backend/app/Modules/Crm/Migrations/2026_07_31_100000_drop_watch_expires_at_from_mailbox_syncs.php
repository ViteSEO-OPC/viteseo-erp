<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops `crm_mailbox_syncs.watch_expires_at`.
 *
 * The column recorded when a Gmail `users.watch` push subscription would lapse, so the daily
 * renewal job knew to re-arm it. Inbound is now a scheduled poll: there is no subscription, so
 * there is no expiry, and nothing reads or writes this column any more.
 *
 * Dropped rather than left in place because a nullable timestamp that is always null is a standing
 * invitation to misread it as "watch not yet armed" — the schema should not describe a mechanism
 * the system no longer has.
 *
 * Reversible: `down()` restores the column in its original position and nullability. The DATA is
 * not recoverable, which is harmless — every value it held described a subscription that no longer
 * exists, and the polling cursor (`sync_cursor`) is the only state resuming a sync depends on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_mailbox_syncs', function (Blueprint $table) {
            $table->dropColumn('watch_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('crm_mailbox_syncs', function (Blueprint $table) {
            $table->timestamp('watch_expires_at')->nullable()->after('sync_cursor');
        });
    }
};
