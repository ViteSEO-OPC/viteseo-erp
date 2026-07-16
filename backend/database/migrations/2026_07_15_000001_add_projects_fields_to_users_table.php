<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive columns the Projects (PMS) module reads off the shared users table.
 *
 * Deliberately NOT included:
 *  - `role` enum: the platform uses Spatie roles as the single source of truth,
 *    so Projects derives Admin/PM/Member from roles (see Projects UserResource).
 *  - `company_id` / companies table: no Projects code references it, so it is
 *    left out rather than dragging in PMS's multi-tenant scaffolding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Profile picture URL/path (Projects avatars + member pickers).
            $table->string('avatar')->nullable()->after('email');
            // Per-user UI preferences blob (profile screen).
            $table->json('preferences')->nullable()->after('avatar');
            // Max open effort (points) before a user is considered overloaded;
            // overload itself is derived, never stored.
            $table->integer('capacity_points')->nullable()->after('preferences');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar', 'preferences', 'capacity_points']);
        });
    }
};
