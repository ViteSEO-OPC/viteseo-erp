<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One (area, niche) task inside a discovery run — the unit the engine is actually invoked for.
 *
 * WHY PER-TASK ROWS RATHER THAN A COUNTER. Progress has to be truthful at the granularity a user
 * asked in: "Cebu City / dentist found 41, Davao / cafe failed because the key was rejected". A
 * single percentage cannot say that, and the Python engine emits no structured progress stream —
 * verified: no `--json`, no `--verbose`, no `logging` usage, only bare `print()` split across
 * stdout and stderr. Rather than parse log lines, the orchestrator invokes the engine ONCE PER TASK
 * and records the outcome here, which makes progress a property of the orchestration instead of a
 * guess about someone else's output format.
 *
 * `provider_used` is per-task on purpose. A run that starts on Google and degrades to OpenStreetMap
 * partway through has tasks of both kinds, and every discovered business must be attributable to the
 * provider that actually returned it.
 *
 * `limit_per_task` is spelled out rather than `limit` because `limit` is a reserved word in MySQL,
 * and a column that needs quoting everywhere it appears is a trap for the next person.
 *
 * Phase 4 (nationwide) will add the descent columns — `parent_id`, `depth`, `bbox`, `admin_level`,
 * `saturated`. They are deliberately NOT created here: Phase 1 performs no geographic subdivision,
 * and a column that nothing writes is a claim the code does not support.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_discovery_run_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id')->constrained('orgs')->cascadeOnDelete();
            $table->foreignId('discovery_run_id')->constrained('crm_discovery_runs')->cascadeOnDelete();

            // Free text, both of them. The area is resolved by the engine's geocoder and the niche is
            // the provider's own vocabulary — neither is validated against a list this codebase owns.
            $table->string('area');
            $table->string('category');
            $table->unsignedSmallInteger('limit_per_task');

            // pending | running | completed | failed | skipped
            $table->string('status', 20)->default('pending');
            $table->string('provider_used', 30)->nullable();
            $table->integer('exit_code')->nullable();

            $table->unsignedInteger('discovered')->default(0);
            $table->unsignedInteger('inserted')->default(0);
            $table->unsignedInteger('duplicates')->default(0);
            $table->unsignedInteger('rejected')->default(0);

            // The engine's own `provider_execution_status` (ok / auth_failed / rate_limited /
            // area_unresolved / not_effective_provider / …) plus a short diagnostic. Never a stack
            // trace and never anything that could carry a credential.
            $table->string('execution_status', 40)->nullable();
            $table->text('error_reason')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['discovery_run_id', 'status'], 'crm_discovery_run_targets_run_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_discovery_run_targets');
    }
};
