<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The descent columns the Phase 1 target table deliberately did not create, plus the run-level
 * counters a nationwide sweep is watched by.
 *
 * WHY THEY ARRIVE NOW AND NOT EARLIER. `create_crm_discovery_run_targets_table` says it: Phase 1
 * performed no geographic subdivision, and "a column that nothing writes is a claim the code does
 * not support". Nationwide writes all of them, so they exist now.
 *
 * THE COUNTERS COUNT CELLS, NOT CITIES, AND THAT IS THE WHOLE DESIGN.
 *
 * Nationwide coverage is the national polygon, tiled. A cell is the unit of work, the unit of
 * progress and the unit of exhaustion; the vendored city anchors label cells so a human can read a
 * run, and they control nothing — not coverage, not quotas, not when a search stops. There is
 * deliberately no per-city target column here, because there is no per-city target: a cell is
 * searched until the PROVIDER has no more to give, and the country is covered until every cell has
 * been asked or the safety budget ends the run.
 *
 * `cells_exhausted` and `cells_capped` are the two ways a cell can finish and they must never be
 * collapsed. Exhausted means the provider returned fewer results than it was allowed to — there is
 * nothing more there. Capped means it returned a full page and the descent limit stopped the split,
 * so there IS more and this run did not get it. One is a fact about the ground, the other a fact
 * about the run.
 *
 * WHAT `admin_level` WOULD HAVE BEEN, AND WHY IT IS NOT HERE. That earlier note also listed
 * `admin_level` and `saturated`. Neither is created. Coverage is GEOMETRIC — cells clipped out of
 * the national polygon — so a search box has no administrative level to record; it is not a barangay
 * or a municipality, it is a rectangle. And "saturated" is not a stored fact but a comparison the
 * orchestrator makes at the moment a task returns (`discovered` against the limit it was given),
 * which is exactly what `discovered` and `limit_per_task` already carry.
 *
 * ALL NULLABLE / DEFAULTED, so every existing scoped row stays valid and unchanged. A scoped run
 * never writes a bbox, because a scoped run has no bbox — it names a place and lets the engine's
 * geocoder resolve it, exactly as it always has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_discovery_run_targets', function (Blueprint $table): void {
            // THE BOX THIS TASK ACTUALLY SEARCHED, as four columns rather than one JSON blob: these
            // are numbers a person compares, filters and eyeballs against a map, and a run that
            // searched the wrong ground must be provable from the row itself.
            //
            // decimal(9,6) is ~0.1 m of precision and cannot drift the way a float can. NULL means
            // "no box" — a scoped task that named a place — which is a different statement from a
            // box of zeros somewhere in the Atlantic.
            $table->decimal('bbox_south', 9, 6)->nullable()->after('category');
            $table->decimal('bbox_west', 9, 6)->nullable()->after('bbox_south');
            $table->decimal('bbox_north', 9, 6)->nullable()->after('bbox_west');
            $table->decimal('bbox_east', 9, 6)->nullable()->after('bbox_north');

            // luzon | visayas | mindanao, derived from the polygon's own landmasses. A DISPLAY
            // dimension: it groups a run's progress into shapes people recognise. It is not a filter
            // and it does not decide where the run looks.
            $table->string('island_group', 12)->nullable()->after('bbox_east');

            // The nearest vendored anchor, purely so a cell can be called "Cebu" instead of
            // "cell 412". LABEL ONLY — see the class docblock.
            $table->string('area')->nullable()->change();

            // The descent. `depth` 0 is a cell straight off the national tiling; `parent_id` points
            // at the cell that came back full and therefore had to be split. Together they make the
            // subdivision auditable: every deep task traces back to the observation that justified
            // creating it.
            $table->unsignedTinyInteger('depth')->default(0)->after('island_group');
            $table->unsignedBigInteger('parent_id')->nullable()->after('depth');

            $table->foreign('parent_id', 'crm_discovery_run_targets_parent_foreign')
                ->references('id')->on('crm_discovery_run_targets')
                ->nullOnDelete();
        });

        Schema::table('crm_discovery_runs', function (Blueprint $table): void {
            // The first-pass tiling: how many cells the national polygon produced at this run's cell
            // size. The denominator for "how much of the country has been asked about", and the only
            // number known before the run starts — everything below it is discovered by running.
            $table->unsignedInteger('cells_planned')->default(0)->after('targets_failed');
            $table->unsignedInteger('cells_first_pass_done')->default(0)->after('cells_planned');

            // Every cell searched, INCLUDING the ones subdivision created. Diverges from
            // `cells_first_pass_done` exactly as much as the country turned out to need splitting,
            // which is the honest measure of how much work a nationwide run really was.
            $table->unsignedInteger('cells_searched')->default(0)->after('cells_first_pass_done');

            // The two ways a cell finishes. Never collapsed — see the class docblock.
            $table->unsignedInteger('cells_exhausted')->default(0)->after('cells_searched');
            $table->unsignedInteger('cells_capped')->default(0)->after('cells_exhausted');
            $table->unsignedInteger('cells_subdivided')->default(0)->after('cells_capped');

            // THE SAFETY BUDGET, in provider calls, recorded rather than merely enforced. A run that
            // stopped early must be able to say it stopped because it ran out of budget — otherwise
            // "we found 3 bakeries in Iloilo" reads as a fact about Iloilo rather than a fact about
            // how much the run was allowed to spend.
            $table->unsignedInteger('budget_total')->default(0)->after('cells_subdivided');
            $table->unsignedInteger('budget_used')->default(0)->after('budget_total');
        });
    }

    public function down(): void
    {
        Schema::table('crm_discovery_run_targets', function (Blueprint $table): void {
            $table->dropForeign('crm_discovery_run_targets_parent_foreign');
            $table->dropColumn([
                'bbox_south', 'bbox_west', 'bbox_north', 'bbox_east',
                'island_group', 'depth', 'parent_id',
            ]);
            $table->string('area')->nullable(false)->change();
        });

        Schema::table('crm_discovery_runs', function (Blueprint $table): void {
            $table->dropColumn([
                'cells_planned', 'cells_first_pass_done', 'cells_searched',
                'cells_exhausted', 'cells_capped', 'cells_subdivided',
                'budget_total', 'budget_used',
            ]);
        });
    }
};
