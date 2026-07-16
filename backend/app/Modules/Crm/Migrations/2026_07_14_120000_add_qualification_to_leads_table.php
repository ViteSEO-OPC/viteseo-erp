<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lead lifecycle qualification — the bridge between the Prospect Pool (Nearby-discovered businesses)
 * and Active CRM Leads.
 *
 * A discovered business (`source` set) is a PROSPECT until a Business Developer qualifies it. Only
 * then does it enter the CRM sales pipeline and participate in Business Computation (Lead Score,
 * dashboards, performance, revenue, pipeline). Manually-created / imported leads (`source` null) are
 * active from creation and never touch this column.
 *
 *  - `qualified_at` — when the prospect was accepted into the CRM (null = still a prospect).
 *  - `qualified_by` — the user who qualified it (audit; nulled if that user is removed).
 *
 * The active/prospect distinction is expressed with EXPLICIT query scopes (`Lead::active()` /
 * `Lead::prospect()`), not a global scope — Business Computation opts in deliberately.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->timestamp('qualified_at')->nullable()->after('assigned_to');
            $table->foreignId('qualified_by')->nullable()->after('qualified_at')->constrained('users')->nullOnDelete();
            $table->index('qualified_at');
        });
    }

    public function down(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->dropForeign(['qualified_by']);
            $table->dropColumn(['qualified_at', 'qualified_by']);
        });
    }
};
