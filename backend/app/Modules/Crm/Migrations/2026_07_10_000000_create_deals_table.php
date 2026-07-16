<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deals (won conversions) — the interim conversion & revenue record for BD Performance.
 *
 * A lead converts into a `deal` (amount + converted_at). This models "Converted Leads" and
 * "Overall Revenue" WITHOUT touching the frozen Lead Status enum (no `converted`/`won` status is
 * added). Approved data-model decision (PO 2026-07-10); realizes the conversion-record the frozen
 * BD-PERF-COMP-SPEC deferred to Phase 2. Ownership is derived from the lead's `assigned_to`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_deals', function (Blueprint $table) {
            $table->id();
            // The lead that converted into this won deal.
            $table->foreignId('lead_id')->constrained('crm_leads')->cascadeOnDelete();
            // Deal value / revenue (pesos). Interim revenue source for BD Performance.
            $table->decimal('amount', 12, 2)->default(0);
            // When the deal was won (conversion timestamp).
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_deals');
    }
};
