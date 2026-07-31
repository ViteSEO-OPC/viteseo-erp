<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `dismissed_at` — the reader taking one notification off their bell dropdown.
 *
 * Purely additive: one nullable column, no existing column or index touched, so every
 * row already in the table reads as "not dismissed" and every existing query behaves
 * exactly as it did.
 *
 * WHY A COLUMN AND NOT A DELETE. Dismissing is not deletion. The History page keeps
 * the complete record (management feedback 2026-07-31 changed the dropdown, not the
 * archive), and the 2026-07-29 retention policy — read rows pruned at 90 days, unread
 * never — stays the only thing that removes a row. This is the "new field" the
 * 2026-07-29 PO decision said an archive would have to be, rather than overloading
 * `read_at` to mean two different things.
 *
 * WHY ON `notifications` AND NOT A SIDE TABLE. This is Laravel's standard table and is
 * unprefixed for that reason, but the CRM is its only consumer (verified 2026-07-29 —
 * HR derives its notifications live from its own domain models and PM has its own
 * `pm_notifications`). A `crm_notification_dismissals` side table would buy isolation
 * nobody needs and cost a NOT EXISTS on the dropdown's hottest query.
 *
 * Nullable timestamp rather than a boolean: WHEN a row was dismissed is the kind of
 * thing support gets asked, and `read_at` next to it already sets that precedent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->timestamp('dismissed_at')->nullable()->after('read_at');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn('dismissed_at');
        });
    }
};
