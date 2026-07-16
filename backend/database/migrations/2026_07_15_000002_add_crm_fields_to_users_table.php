<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive columns the CRM (Zerem) module reads off the shared users table.
 *
 *  - `status` (active|inactive): CRM Team Accounts — an inactive account is
 *     blocked from signing in (enforcement is a shared-auth TODO; the column +
 *     management UI work today).
 *  - `last_login_at`: stamped on successful login (CRM "Last Login" column).
 *  - soft-deletes (`deleted_at`): CRM "Delete" is reversible and preserves the
 *     crm_leads.created_by FK — CRM's UserController@destroy calls $user->delete().
 *
 * Adding SoftDeletes to the shared User is additive/low-risk: existing HR and
 * Projects queries transparently gain `whereNull(deleted_at)` and no user is
 * soft-deleted in practice, so their behaviour is unchanged. Each column is
 * guarded so this is safe to run alongside any pre-existing schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'status')) {
                $table->string('status')->default('active')->after('email');
            }
            if (! Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('users', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
            foreach (['status', 'last_login_at'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
