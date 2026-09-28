<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-run AI search planning: whether a run ASKED for it, and what actually happened.
 *
 * TWO COLUMNS BECAUSE THEY ARE TWO FACTS. "The user asked for AI" is known the moment the run is
 * created; "AI shaped this run" is only knowable once the engine reports it searched with a plan.
 * A run can ask and not get it (the account ran out of credit), which is exactly the case the UI
 * must be able to state instead of implying.
 *
 * DEFAULTED, so every existing row reads as "not requested" and keeps behaving exactly as before.
 * `ai_outcome` is NULL until the first search reports; the resource reads NULL as `not_applied`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_discovery_runs', function (Blueprint $table): void {
            $table->boolean('ai_strategy_requested')->default(false)->after('scope');
            // applied | not_applied | insufficient_credit — see DiscoveryRun::AI_*.
            $table->string('ai_outcome', 32)->nullable()->after('ai_strategy_requested');
        });
    }

    public function down(): void
    {
        Schema::table('crm_discovery_runs', function (Blueprint $table): void {
            $table->dropColumn(['ai_strategy_requested', 'ai_outcome']);
        });
    }
};
