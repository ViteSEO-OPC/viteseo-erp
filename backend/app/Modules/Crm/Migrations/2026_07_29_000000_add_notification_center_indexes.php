<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the Notification Center's two hot queries. Purely additive: no column
 * is added, changed or dropped, and no existing index is touched.
 *
 * The table shipped with only `PRIMARY(id)` and the standard morphs index on
 * (notifiable_type, notifiable_id). Both Notification Center queries filter by
 * recipient and then either test `read_at` or order by `created_at`, so within one
 * recipient's rows the unread count filters and the feed sorts — fine at today's
 * volumes, a filesort on every page load once a busy user accumulates history.
 *
 *   unread badge : … WHERE notifiable_* = ? AND read_at IS NULL
 *   feed / cursor: … WHERE notifiable_* = ? ORDER BY created_at DESC
 *
 * The existing 2-column morphs index becomes a prefix of both of these. It is left
 * in place deliberately: it is created by Laravel's `morphs()`, so anything using
 * the Notifiable relation expects it, and dropping it would not be additive.
 *
 * (An earlier version of this note said HR relies on this table. It does not —
 * verified 2026-07-29: HR derives its notifications live from its own domain models
 * and writes no rows here. The CRM is this table's only consumer today; it is
 * unprefixed because it is Laravel's standard table, not because it is shared in
 * practice.)
 *
 * Index names are given explicitly — the generated names for three-column indexes
 * on this table would be long and inconsistent with the existing one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->index(
                ['notifiable_type', 'notifiable_id', 'read_at'],
                'notifications_notifiable_read_at_index'
            );

            $table->index(
                ['notifiable_type', 'notifiable_id', 'created_at'],
                'notifications_notifiable_created_at_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_notifiable_read_at_index');
            $table->dropIndex('notifications_notifiable_created_at_index');
        });
    }
};
