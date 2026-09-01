<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Lead;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The qualification bridge: Prospect Pool → Active CRM Lead, and specifically its ATOMICITY.
 *
 * Qualifying is a CLAIM on a shared, unassigned prospect. The pool is visible to every BD, so two of
 * them can press "Add to Leads" on the same row at the same moment. Before the fix this was a
 * time-of-check/time-of-use gap: the controller checked `qualified_at` on the route-bound instance,
 * so both requests could pass the guard, both receive 201, and the second UPDATE overwrite the first
 * claimant's ownership — with the observer firing twice (two Activities, two scoring runs).
 *
 * The endpoint now re-reads the row under `lockForUpdate` inside a transaction and evaluates its
 * guards against THAT row. These tests pin both halves: the ordinary behaviour (unchanged) and the
 * claim being exactly-once.
 */
class LeadQualificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function bd(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('crm_bd');

        return $user;
    }

    /**
     * An unqualified discovered business — the shape the scraper lands. `no-website` guarantees a
     * non-zero Lead Score once qualified, so the scoring assertion below is deterministic.
     */
    private function prospect(): Lead
    {
        return Lead::factory()->create([
            'source' => 'openstreetmap',
            'qualified_at' => null,
            'qualified_by' => null,
            'assigned_to' => null,
            'status' => 'new',
            'score' => 0,
            'website_status' => 'no-website',
        ]);
    }

    // ── 1. Normal qualification ─────────────────────────────────────────────────────────────────

    public function test_a_business_developer_can_qualify_a_prospect(): void
    {
        $bd = $this->bd();
        Sanctum::actingAs($bd);
        $prospect = $this->prospect();

        $this->postJson("/api/v1/crm/leads/{$prospect->id}/qualify")
            ->assertCreated()
            ->assertJsonPath('data.id', (string) $prospect->id);

        $this->assertNotNull($prospect->fresh()->qualified_at);
    }

    // ── 2. Already qualified → 409 ──────────────────────────────────────────────────────────────

    public function test_qualifying_an_already_qualified_prospect_is_refused(): void
    {
        $bd = $this->bd();
        Sanctum::actingAs($bd);
        $prospect = $this->prospect();
        $prospect->update(['qualified_at' => now(), 'qualified_by' => $bd->id, 'assigned_to' => $bd->id]);

        $this->postJson("/api/v1/crm/leads/{$prospect->id}/qualify")->assertStatus(409);
    }

    // ── 2b. The 409 names the BD who won, so the loser knows the pool moved ─────────────────────

    /**
     * The refusal has to be actionable. "Already qualified" tells a BD nothing about WHY the row they
     * were looking at is gone; naming the winner does. The name comes from the existing
     * `qualified_by` relation — no new column, projection or claiming concept.
     */
    public function test_the_409_names_the_bd_who_qualified_it_first(): void
    {
        $winner = $this->bd();
        $winner->update(['name' => 'Jhamire Aranda']);
        $loser = $this->bd();

        Sanctum::actingAs($loser);
        $prospect = $this->prospect();
        $prospect->update([
            'qualified_at' => now(),
            'qualified_by' => $winner->id,
            'assigned_to' => $winner->id,
        ]);

        $this->postJson("/api/v1/crm/leads/{$prospect->id}/qualify")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Already added to CRM by Jhamire Aranda.');
    }

    /** Re-pressing your OWN claim must not report a stranger took it — that reads as a bug. */
    public function test_the_409_addresses_the_actor_when_they_are_the_claimant(): void
    {
        $bd = $this->bd();
        Sanctum::actingAs($bd);
        $prospect = $this->prospect();
        $prospect->update(['qualified_at' => now(), 'qualified_by' => $bd->id, 'assigned_to' => $bd->id]);

        $this->postJson("/api/v1/crm/leads/{$prospect->id}/qualify")
            ->assertStatus(409)
            ->assertJsonPath('message', 'You have already added this prospect to the CRM.');
    }

    /** An unresolvable claimant degrades to a generic sentence rather than leaking a raw id. */
    public function test_the_409_falls_back_when_the_claimant_cannot_be_resolved(): void
    {
        Sanctum::actingAs($this->bd());
        $prospect = $this->prospect();
        // qualified_by NULL is reachable: the column is nullable and a claimant can be removed later.
        $prospect->update(['qualified_at' => now(), 'qualified_by' => null]);

        $this->postJson("/api/v1/crm/leads/{$prospect->id}/qualify")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Already added to CRM by another BD.');
    }

    // ── 2c. A stale client hammering the dead row changes nothing ───────────────────────────────

    /**
     * The stale-client scenario the Nearby UX fix targets. A BD whose list predates the claim can
     * press the button repeatedly; every attempt must be refused identically and must not disturb the
     * winner's ownership, attribution, or the Activity trail.
     */
    public function test_repeated_attempts_on_a_claimed_prospect_never_steal_it(): void
    {
        $winner = $this->bd();
        $winner->update(['name' => 'Jhamire Aranda']);
        $loser = $this->bd();

        Sanctum::actingAs($loser);
        $prospect = $this->prospect();
        $qualifiedAt = now()->subMinute();
        $prospect->update([
            'qualified_at' => $qualifiedAt,
            'qualified_by' => $winner->id,
            'assigned_to' => $winner->id,
        ]);

        $activitiesBefore = Activity::query()->where('lead_id', $prospect->id)->count();

        foreach (range(1, 3) as $attempt) {
            $this->postJson("/api/v1/crm/leads/{$prospect->id}/qualify")
                ->assertStatus(409)
                ->assertJsonPath('message', 'Already added to CRM by Jhamire Aranda.');
        }

        $fresh = $prospect->fresh();
        $this->assertSame($winner->id, $fresh->qualified_by, 'attribution moved');
        $this->assertSame($winner->id, $fresh->assigned_to, 'ownership moved');
        $this->assertSame(
            $qualifiedAt->toDateTimeString(),
            $fresh->qualified_at->toDateTimeString(),
            'the claim timestamp was rewritten',
        );
        $this->assertSame(
            $activitiesBefore,
            Activity::query()->where('lead_id', $prospect->id)->count(),
            'a refused attempt wrote an Activity',
        );
    }

    // ── 3 + 4. Ownership and attribution go to the ACTING user ──────────────────────────────────

    public function test_ownership_and_attribution_are_the_acting_user(): void
    {
        $bd = $this->bd();
        Sanctum::actingAs($bd);
        $prospect = $this->prospect();

        $this->postJson("/api/v1/crm/leads/{$prospect->id}/qualify")->assertCreated();

        $fresh = $prospect->fresh();
        $this->assertSame($bd->id, $fresh->assigned_to);
        $this->assertSame($bd->id, $fresh->qualified_by);
    }

    // ── 5. Status is a sales decision, not a lifecycle side effect ──────────────────────────────

    public function test_qualification_does_not_change_status_or_priority(): void
    {
        $bd = $this->bd();
        Sanctum::actingAs($bd);
        $prospect = $this->prospect();
        $priorityBefore = $prospect->priority;

        $this->postJson("/api/v1/crm/leads/{$prospect->id}/qualify")->assertCreated();

        $fresh = $prospect->fresh();
        $this->assertSame('new', $fresh->status);
        $this->assertSame($priorityBefore, $fresh->priority);
    }

    // ── 6. Exactly one qualification Activity ───────────────────────────────────────────────────

    public function test_exactly_one_qualified_activity_is_written(): void
    {
        $bd = $this->bd();
        Sanctum::actingAs($bd);
        $prospect = $this->prospect();

        $this->postJson("/api/v1/crm/leads/{$prospect->id}/qualify")->assertCreated();

        $activities = Activity::query()
            ->where('subject_id', $prospect->id)
            ->where('event', 'lead.qualified')
            ->get();

        $this->assertCount(1, $activities);
        $this->assertSame($bd->id, $activities->first()->causer_id);
    }

    // ── 7. Scoring still happens, exactly once ──────────────────────────────────────────────────

    public function test_the_lead_score_is_computed_on_qualification(): void
    {
        $bd = $this->bd();
        Sanctum::actingAs($bd);
        $prospect = $this->prospect();
        $this->assertSame(0, $prospect->score, 'a prospect carries no CRM score until qualified');

        $this->postJson("/api/v1/crm/leads/{$prospect->id}/qualify")->assertCreated();

        $this->assertGreaterThan(0, $prospect->fresh()->score);
        // The scorer writes with updateQuietly — it must not add a second Activity of its own.
        $this->assertSame(
            1,
            Activity::query()->where('subject_id', $prospect->id)->where('event', 'lead.qualified')->count(),
        );
    }

    // ── 8. Concurrency: the claim is exactly-once ───────────────────────────────────────────────

    /**
     * Proves the PROTECTION MECHANISM rather than sequential calls.
     *
     * The race window is between route-model binding (which reads the row) and the write. A
     * `retrieved` hook fires exactly once, on that binding read, and simulates a competing BD
     * winning the claim in that window — writing straight to the table so the interleaving is real
     * and observer-free.
     *
     * With the fix, the controller re-reads under `lockForUpdate` and sees the competitor's
     * `qualified_at` → 409, ownership untouched. WITHOUT the fix the controller would have trusted
     * the stale bound instance (`qualified_at` still NULL), returned 201, and overwritten the
     * winner's ownership — so this test fails on the old code and passes on the new.
     */
    public function test_a_competing_claim_in_the_race_window_is_refused_and_does_not_steal_ownership(): void
    {
        $winner = $this->bd();
        $loser = $this->bd();
        Sanctum::actingAs($loser);
        $prospect = $this->prospect();

        $qualifiedAt = now()->subSecond();
        $fired = false;

        Lead::retrieved(function (Lead $model) use (&$fired, $prospect, $winner, $qualifiedAt): void {
            if ($fired || $model->getKey() !== $prospect->id) {
                return;
            }
            $fired = true;
            // The competing BD commits first, in the window before our lock is taken.
            DB::table('crm_leads')->where('id', $prospect->id)->update([
                'qualified_at' => $qualifiedAt,
                'qualified_by' => $winner->id,
                'assigned_to' => $winner->id,
            ]);
        });

        $this->postJson("/api/v1/crm/leads/{$prospect->id}/qualify")->assertStatus(409);

        $this->assertTrue($fired, 'the race window was never exercised — the test proved nothing');

        $fresh = $prospect->fresh();
        $this->assertSame($winner->id, $fresh->assigned_to, 'the first claimant must keep ownership');
        $this->assertSame($winner->id, $fresh->qualified_by);
        $this->assertSame(
            $qualifiedAt->toDateTimeString(),
            $fresh->qualified_at->toDateTimeString(),
            'the winner\'s qualification timestamp must not be overwritten',
        );
        // The refused claim must leave no trace: no Activity, no second scoring run.
        $this->assertSame(
            0,
            Activity::query()->where('subject_id', $prospect->id)->where('event', 'lead.qualified')->count(),
            'a refused claim must not log a qualification',
        );
    }

    /** The claim runs inside a transaction, so a refusal leaves nothing half-written. */
    public function test_the_qualification_runs_in_a_transaction(): void
    {
        $bd = $this->bd();
        Sanctum::actingAs($bd);
        $prospect = $this->prospect();

        $depths = [];
        DB::listen(function () use (&$depths): void {
            $depths[] = DB::transactionLevel();
        });

        $this->postJson("/api/v1/crm/leads/{$prospect->id}/qualify")->assertCreated();

        // RefreshDatabase wraps each test in one transaction, so the endpoint's own transaction
        // shows as a deeper level than the test's baseline.
        $this->assertGreaterThan(
            min($depths),
            max($depths),
            'the qualification did not open its own transaction',
        );
    }
}
