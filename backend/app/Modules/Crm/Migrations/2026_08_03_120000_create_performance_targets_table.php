<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Goal Management — the single source of the target denominators the CRM has been rendering as
 * "Pending" since the Reports & Performance milestone: the BD's monthly Personal Target and the
 * quarterly Quarter Targets tiles. One table serves both, so there is no second target system.
 *
 * `user_id` is NOT NULL by product decision: every target belongs to one Business Development
 * Manager. Admin/Super Admin "All Reports" shows individual BD targets rather than a fabricated
 * team aggregate — a CRM-wide target would be a new business object and needs its own requirement.
 *
 * `period_start` normalises both cadences to a single anchor: the 1st of the month for a monthly
 * target, the 1st of the quarter for a quarterly one. That keeps "which target applies now" a plain
 * equality lookup instead of range arithmetic, and lets both cadences share one unique key.
 *
 * Targets are configuration, never results. Nothing here overwrites a computed performance figure —
 * they supply denominators only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_performance_targets', function (Blueprint $table) {
            $table->id();

            // The BD this target is set for. Cascades: a deleted user's targets have no subject.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('period_type');   // month | quarter
            $table->date('period_start');    // 1st of the month, or 1st of the quarter
            $table->string('metric');        // converted_leads | leads_contacted | consultations | confirmed_clients
            $table->unsignedInteger('target_value');

            // Retire a target without deleting it, so the audit trail survives.
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // One target per BD per period per metric. Soft-deleted rows keep the slot occupied,
            // which is deliberate: restoring is preferable to silently creating a duplicate. The
            // service treats a trashed row as free and restores it instead of inserting.
            $table->unique(['user_id', 'period_type', 'period_start', 'metric'], 'crm_perf_target_unique');

            // The read path every consumer uses: "the active target(s) for this subject and period".
            $table->index(['user_id', 'period_type', 'period_start'], 'crm_perf_target_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_performance_targets');
    }
};
