<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-tenancy core for CRM (Zerem): stamp every crm_* table with the owning
 * org so the BelongsToOrg global scope can fence each tenant's data. Mirrors
 * the Projects template.
 *
 * PORTED from kps (2026_08_18_210000) on 2026-09-03. Two differences, both because
 * this repo's CRM has drifted from that one:
 *
 *   - Every loop guards on Schema::hasTable(). Five crm_* tables exist there and
 *     not here, and five exist here and not there; an unguarded loop dies on the
 *     first absent one and leaves the schema half-stamped.
 *   - Five tables the pods added here join the list (see BD/website/access below).
 *
 * ONE TABLE IS DELIBERATELY LEFT GLOBAL: `crm_nerby_provider_settings`. It holds
 * the discovery providers' API CREDENTIALS -- the production home for what used to
 * live in the scraper's .env -- and those are the platform's keys, bought and paid
 * for by the provider, not by any tenant. Stamping it with org_id would invite each
 * client org to edit the platform's own credentials. It stays global and must be
 * writable ONLY by the platform super admin; that gate is a route concern, not a
 * schema one. Flagged for Julius: if a client is ever meant to bring their own key,
 * this decision flips and the table needs org_id after all.
 *
 * All 21 crm_* tables are CONTENT tables (there are no attach()/sync() pivots
 * in the CRM schema), so every one of them ends `org_id NOT NULL` after the
 * backfill. The shared `notifications` table (created by a CRM migration but
 * owned by Laravel's database-notifications channel) is deliberately NOT
 * touched: its rows are already fenced per user via `notifiable_id`, and other
 * modules notify through it too.
 *
 * Backfill: production is single-org today, so `org_id = first org` is safe by
 * definition (same 2026-08 audit as Projects). On a fresh install there are no
 * rows (except the migration-seeded lead_scoring/kpi baseline versions, see
 * below) and possibly no org yet — the backfill is then a no-op and the NOT
 * NULL change applies only to tables with no NULL rows left.
 *
 * ── Global unique indexes that break under multi-tenancy ─────────────────────
 * Six unique indexes were declared BEFORE tenancy and are global, so two orgs
 * could never hold the same value. Each is dropped and re-created org-scoped:
 *
 *   crm_niches.name                              → unique(org_id, name)
 *   crm_mailbox_oauth_tokens.email               → unique(org_id, email)
 *   crm_mailbox_syncs.email                      → unique(org_id, email)
 *   crm_emails (provider, message_id)            → unique(org_id, provider, message_id)
 *   crm_emails (automation_event_id, purpose)    → unique(org_id, automation_event_id, purpose)
 *   crm_business_rule_versions (module, version_number)
 *                                                → unique(org_id, module, version_number)
 *
 * Kept AS-IS (already tenant-safe — their key columns are org-scoped ids):
 *   crm_lead_website_enrichments.lead_id (1:1 per lead), crm_reminder_deliveries
 *   (planner_event_id, channel), crm_business_rules (version_id, rule_key),
 *   crm_notification_deliveries (email_id, channel), and crm_performance_targets
 *   (user_id, period, metric) — a user belongs to exactly one org (the
 *   2026_08_18_100000 single-org invariant).
 *
 * ── Seeded business-rules baseline ───────────────────────────────────────────
 * 2026_07_25_040000 / 2026_08_03_120100 seed the lead_scoring/kpi baselines as
 * data migrations. On existing installs those rows backfill to the first org
 * like everything else. On a FRESH install they are inserted before any org
 * exists, so they stay org-NULL and invisible to every tenant — the
 * ScoringConfigProvider then falls back to config/scoring.php by design. Tests
 * adopt the orphan baseline into their fixture org (see CrmTestCase).
 */
return new class extends Migration
{
    /** Every crm_* table (21). All content — org_id becomes NOT NULL after backfill. */
    private const TABLES = [
        'crm_leads',
        'crm_activities',
        'crm_emails',
        'crm_email_events',
        'crm_email_templates',
        'crm_mailbox_oauth_tokens',
        'crm_mailbox_syncs',
        'crm_notes',
        'crm_deals',
        'crm_service_areas',
        'crm_planner_events',
        'crm_lead_website_enrichments',
        'crm_reminder_deliveries',
        'crm_niches',
        'crm_calls',
        'crm_business_rule_versions',
        'crm_business_rules',
        'crm_business_rule_audit',
        'crm_notification_deliveries',
        'crm_lead_attachments',
        'crm_performance_targets',

        // ── Added by the pods in this repo, absent from the kps port ──────────
        // Per-user suspension. `user_id` is UNIQUE and stays that way: a user
        // belongs to exactly ONE org (see 2026_09_03_090000), so a per-user row
        // is already a per-org row. The column is here so the scope can fence
        // reads, not because the index needed widening.
        'crm_user_access',
        // A snapshot of one BD's numbers. Per user, therefore per org.
        'crm_bd_performance_snapshots',
        // Both hang off crm_leads, which is org-scoped -- but a child that
        // carries no org of its own can only be fenced through a join, and the
        // raw-query paths in this module do not always join.
        'crm_lead_website_candidates',
        'crm_lead_website_searches',
    ];

    /**
     * Re-scope one global unique index to the org, skipping anything absent.
     *
     * The index NAMES are the ones this schema happens to carry; kps and this repo
     * have drifted, and a dropUnique() on a name that is not there aborts the whole
     * migration. Nothing here should fail because a pod renamed a table.
     *
     * @param  list<string>  $columns
     */
    private function rescopeUnique(string $table, string $dropName, array $columns, ?string $newName = null): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'org_id')) {
            return;
        }

        $indexes = collect(Schema::getIndexes($table));

        if ($indexes->contains(fn (array $i): bool => $i['name'] === $dropName)) {
            Schema::table($table, function (Blueprint $blueprint) use ($dropName): void {
                $blueprint->dropUnique($dropName);
            });
        }

        $wanted = $newName ?? $table.'_'.implode('_', $columns).'_unique';
        if ($indexes->contains(fn (array $i): bool => $i['name'] === $wanted)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $newName): void {
            $blueprint->unique($columns, $newName);
        });
    }

    public function up(): void
    {
        // 1) Add the column nullable first so existing rows survive the ALTER
        //    on both MySQL (prod) and SQLite (tests).
        foreach (self::TABLES as $table) {
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
            foreach (self::TABLES as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                DB::table($table)->whereNull('org_id')->update(['org_id' => $firstOrgId]);
            }
        }

        // 3) Lock the tables down to NOT NULL. Guarded per table: if a row
        //    somehow still has no org (rows exist but the orgs table is empty —
        //    e.g. the migration-seeded business-rules baseline on a fresh
        //    install), leave that table nullable rather than fail mid-migration.
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || DB::table($table)->whereNull('org_id')->exists()) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->unsignedBigInteger('org_id')->nullable(false)->change();
            });
        }

        // 4) Re-scope the six pre-tenancy GLOBAL unique indexes to the org, so two
        //    tenants can now hold the same value while each stays unique in its own.
        $this->rescopeUnique('crm_niches', 'crm_niches_name_unique', ['org_id', 'name']);
        $this->rescopeUnique('crm_mailbox_oauth_tokens', 'crm_mailbox_oauth_tokens_email_unique', ['org_id', 'email']);
        $this->rescopeUnique('crm_mailbox_syncs', 'crm_mailbox_syncs_email_unique', ['org_id', 'email']);

        // Inbound idempotency: the same RFC Message-ID may legitimately land in TWO
        // orgs' mailboxes (one message sent to both tenants), so the dedup claim is
        // per-org -- matching InboundEmailService, which runs under the receiving
        // mailbox's org.
        $this->rescopeUnique('crm_emails', 'crm_emails_provider_message_id_unique',
            ['org_id', 'provider', 'message_id'], 'crm_emails_org_provider_message_id_unique');

        // n8n automation idempotency claim -- event ids are per-tenant.
        $this->rescopeUnique('crm_emails', 'crm_emails_automation_event_purpose_unique',
            ['org_id', 'automation_event_id', 'purpose'], 'crm_emails_org_automation_event_purpose_unique');

        // Version numbers are monotonic PER ORG per module now -- each tenant owns
        // its own Business Rules timeline starting at its own v1.
        $this->rescopeUnique('crm_business_rule_versions', 'crm_business_rule_versions_module_version_number_unique',
            ['org_id', 'module', 'version_number'], 'crm_business_rule_versions_org_module_version_unique');
    }

    public function down(): void
    {
        Schema::table('crm_business_rule_versions', function (Blueprint $blueprint): void {
            $blueprint->dropUnique('crm_business_rule_versions_org_module_version_unique');
            $blueprint->unique(['module', 'version_number']);
        });

        Schema::table('crm_emails', function (Blueprint $blueprint): void {
            $blueprint->dropUnique('crm_emails_org_automation_event_purpose_unique');
            $blueprint->unique(['automation_event_id', 'purpose'], 'crm_emails_automation_event_purpose_unique');

            $blueprint->dropUnique('crm_emails_org_provider_message_id_unique');
            $blueprint->unique(['provider', 'message_id']);
        });

        Schema::table('crm_mailbox_syncs', function (Blueprint $blueprint): void {
            $blueprint->dropUnique(['org_id', 'email']);
            $blueprint->unique('email');
        });

        Schema::table('crm_mailbox_oauth_tokens', function (Blueprint $blueprint): void {
            $blueprint->dropUnique(['org_id', 'email']);
            $blueprint->unique('email');
        });

        Schema::table('crm_niches', function (Blueprint $blueprint): void {
            $blueprint->dropUnique(['org_id', 'name']);
            $blueprint->unique('name');
        });

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'org_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                // SQLite refuses to drop a column a standalone index still
                // references, so the index goes first (MySQL drops it with the
                // column either way; the explicit drop is a harmless no-op there).
                $blueprint->dropIndex("{$table}_org_id_index");
                $blueprint->dropConstrainedForeignId('org_id');
            });
        }
    }
};
