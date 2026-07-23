<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business Niche registry — the canonical vocabulary behind the shared Business Niche selector.
 *
 * REGISTRY, NOT A FOREIGN KEY (PO-approved). Leads keep storing the niche as the free-text
 * `crm_leads.industry` string; this table is the vocabulary that the selector offers and that the
 * Add Business Niche modal manages. Nothing downstream changes: Reports (industry bars), Nearby
 * Clients, Planner, Dashboard, CSV export, the `{{industry}}` email merge token, the `?industry=`
 * filter and the Excel-import header aliases all keep reading `crm_leads.industry` untouched.
 *
 * Why a table at all — three things a derived-from-leads list cannot express:
 *   1. a niche with ZERO leads (the modal's "No custom niches yet" empty state),
 *   2. a stable identity to RENAME (the string itself is the only identity today),
 *   3. built-in vs custom, which decides what may be renamed or deleted.
 *
 * `name` is uniquely indexed as a database-level backstop against exact-duplicate races. It is NOT
 * what makes the vocabulary case-insensitive: index comparison follows the connection's collation
 * (case-insensitive on this MySQL, case-SENSITIVE on the SQLite the test suite uses), so the
 * "Retail" vs "retail" rule is enforced explicitly by the UniqueNicheName validation rule instead.
 *
 * Schema only. The built-in rows are NOT seeded here: the canonical built-in list is still under
 * PO review, and populating the registry is a separate, idempotent step (NicheSeeder +
 * `crm:backfill-niches`) run once that list is approved. The registry therefore starts empty and
 * the feature degrades to "custom niches only" until then — no invented vocabulary ships.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_niches', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            // false = built-in: immutable. Cannot be renamed or deleted through the API, so the
            // approved canonical vocabulary cannot be edited away by a BD from the modal.
            $table->boolean('is_custom')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_niches');
    }
};
