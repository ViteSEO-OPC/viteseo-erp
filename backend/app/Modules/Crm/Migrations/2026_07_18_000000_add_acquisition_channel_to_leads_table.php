<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lead Acquisition Channel — the business "Source" (HOW a lead was acquired: Referral, Facebook,
 * Website, Cold Outreach, LinkedIn, Walk-in, Event, Partner, Email Campaign, Other).
 *
 * Deliberately SEPARATE from `crm_leads.source`, which is discovery PROVENANCE and drives the
 * Active/Prospect business-computation scopes (Lead::scopeActive/scopeProspect). That field keeps its
 * meaning untouched. `acquisition_channel` is pure business metadata: it participates in NO scope,
 * KPI, Lead Score, dashboard, report, pipeline, or revenue computation.
 *
 * Additive + nullable + backfill-free: existing rows stay NULL; manual create + Excel import populate
 * it going forward. Stored as a plain string (not a DB enum) so enterprise imports never lose a
 * channel value that isn't in the canonical dropdown list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->string('acquisition_channel')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->dropColumn('acquisition_channel');
        });
    }
};
