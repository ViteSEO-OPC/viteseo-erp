<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-tenancy core for Projects (Sprintly): stamp every pm_* table with the
 * owning org so the BelongsToOrg global scope can fence each tenant's data.
 *
 * PORTED from the kps repo (2026_08_18_200000), where this shipped first, with two
 * changes: every loop guards on Schema::hasTable() because the two repos' module
 * tables have drifted, and pm_workspace_members joins the pivot list.
 *
 * Content tables end up `org_id NOT NULL`; the three membership/link pivots
 * (pm_project_members, pm_group_members, pm_project_group) keep the column
 * NULLABLE because their rows are written through belongsToMany attach()/sync(),
 * which bypasses model events (no auto-stamp). Both sides of each pivot are
 * already org-scoped, so a pivot row can never bridge two orgs — this mirrors
 * Booking's documented "pure pivot needs no org_id of its own" precedent while
 * still carrying the column for reporting/defense-in-depth.
 *
 * Backfill: production is single-org today, so `org_id = first org` is safe by
 * definition (2026-08-17 audit). On a fresh install there are no rows and no
 * org yet — the backfill is a no-op and the NOT NULL change applies to empty
 * tables.
 */
return new class extends Migration
{
    /** Content tables — org_id becomes NOT NULL after the backfill. */
    private const CONTENT_TABLES = [
        'pm_workspaces',
        'pm_projects',
        'pm_project_statuses',
        'pm_groups',
        'pm_tasks',
        'pm_subtasks',
        'pm_comments',
        'pm_links',
        'pm_activity_logs',
        'pm_notifications',
        'pm_notes',
        'pm_workload_settings',
    ];

    /** Pivot/link tables — org_id stays nullable (attach() bypasses events). */
    private const PIVOT_TABLES = [
        'pm_project_members',
        'pm_group_members',
        'pm_project_group',
        // Workspace membership. Missing from the original list, and it is the one
        // pivot that can actually bridge two orgs: it joins an org-scoped workspace
        // to a SHARED user. The scope alone cannot stop that -- OrgUserRule is what
        // refuses the cross-org user at validation time -- but the column belongs
        // here for the same defence-in-depth reason as the other three.
        'pm_workspace_members',
    ];

    public function up(): void
    {
        $tables = [...self::CONTENT_TABLES, ...self::PIVOT_TABLES];

        // 1) Add the column nullable first so existing rows survive the ALTER
        //    on both MySQL (prod) and SQLite (tests).
        foreach ($tables as $table) {
            // Guarded on the TABLE, not just the column. This migration is a port
            // from the kps repo, whose module tables have drifted from this one --
            // seven exist there and not here. An unguarded loop fails mid-migration
            // on the first absent table and leaves the schema half-stamped.
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'org_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                // index() names the index explicitly so MySQL reuses it for the
                // FK instead of creating a duplicate, and SQLite (which never
                // auto-indexes FKs) gets one too.
                $blueprint->foreignId('org_id')
                    ->nullable()
                    ->index()
                    ->constrained('orgs')
                    ->cascadeOnDelete();
            });
        }

        // 2) Backfill: single-org production — everything belongs to the first
        //    (provider) org. No org yet means a fresh install: nothing to fill.
        $firstOrgId = DB::table('orgs')->orderBy('id')->value('id');
        if ($firstOrgId !== null) {
            foreach ($tables as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                DB::table($table)->whereNull('org_id')->update(['org_id' => $firstOrgId]);
            }
        }

        // 3) Lock the content tables down to NOT NULL. Guarded per table: if a
        //    row somehow still has no org (rows exist but the orgs table is
        //    empty), leave that table nullable rather than fail mid-migration.
        foreach (self::CONTENT_TABLES as $table) {
            if (! Schema::hasTable($table) || DB::table($table)->whereNull('org_id')->exists()) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->unsignedBigInteger('org_id')->nullable(false)->change();
            });
        }

        // One workload-settings row per org (WorkloadSetting::current() is
        // firstOrCreate keyed on org_id — the unique index backs that up).
        Schema::table('pm_workload_settings', function (Blueprint $blueprint): void {
            $blueprint->unique('org_id');
        });
    }

    public function down(): void
    {
        Schema::table('pm_workload_settings', function (Blueprint $blueprint): void {
            $blueprint->dropUnique(['org_id']);
        });

        foreach ([...self::CONTENT_TABLES, ...self::PIVOT_TABLES] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'org_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropConstrainedForeignId('org_id');
            });
        }
    }
};
