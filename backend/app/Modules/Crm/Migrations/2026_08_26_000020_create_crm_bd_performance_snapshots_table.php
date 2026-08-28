<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A BD's performance, frozen at a moment in time.
 *
 * WHY THIS HAS TO EXIST. Every CRM performance number — M-01…M-06, C1…C4, the BDR index, BD
 * Performance, Reports — is computed LIVE from `crm_leads.assigned_to`. Nothing is persisted, so a
 * BD's record is not stored anywhere: it is a derivation from the portfolio they hold right now. The
 * moment a resigned BD's leads are handed to someone else, the custody-based metrics follow the
 * leads and their history is simply gone — not zeroed, ungettable. Preserving it means recording it
 * BEFORE ownership moves, which is what this table is for.
 *
 * WHY `cohort` IS NOT OPTIONAL. {@see \App\Modules\Crm\Services\Ranking\BdRankingCalculator} applies
 * peer-relative min-max normalization, so a BDR index means nothing without the peer set it was
 * normalized against. Storing the index alone would preserve a number that can never be interpreted
 * or defended again. The whole cohort is captured with it.
 *
 * WHAT THIS IS NOT. It is not an audit log and not a replacement for provenance — `crm_activities`,
 * `crm_calls` and the completed Planner rows remain the permanent record of who did what. This is a
 * reporting artefact: the shape of one BD's performance on the day they stopped working the pipeline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_bd_performance_snapshots', function (Blueprint $table) {
            $table->id();

            // NOT unique: a BD may be suspended, reactivated and suspended again, and each of those
            // is a distinct moment worth keeping. Reading "the latest" is an ordered query.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->timestamp('captured_at');

            // What prompted the capture: `suspension` today, `reassignment` once a bulk handover can
            // drain a portfolio without a prior suspension. An open string, constrained at the service
            // layer, mirroring the leads/planner convention for controlled domain values.
            $table->string('reason');

            // M-01…M-06 as returned by BdKpiService, plus the BD's own C1…C4 raw/normalized
            // components, BDR index, rank and unranked reason.
            $table->json('metrics');

            // Every other BD in the same ranking run, plus the run's status/interim flags — the peer
            // context without which the index above is meaningless.
            $table->json('cohort');

            $table->timestamps();

            // The only read pattern: this user's snapshots, newest first.
            $table->index(['user_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_bd_performance_snapshots');
    }
};
