<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Enums\ConsultationOutcome;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Reports & Performance management analytics (`/dashboard/reports-analytics`). Rides the same scope seam
 * as the other Performance endpoints, so it inherits the same security contract; this locks the payload
 * SHAPE, the ownership scoping (a drill returns exactly that BD's own aggregate), the SURVIVAL-funnel
 * nesting invariant, and the honest "no fabricated targets" flag.
 */
class ReportsAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function user(string $role, string $status = 'active'): User
    {
        $user = User::factory()->create(['status' => $status]);
        $user->assignRole($role);

        return $user;
    }

    public function test_returns_every_analytics_block_with_a_survival_funnel(): void
    {
        Sanctum::actingAs($this->user('crm_admin'));

        $data = $this->getJson('/api/v1/crm/dashboard/reports-analytics')
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'funnel' => ['stages', 'biggestDropOff'],
                'consultationsHeld',
                'consultationsAwaitingOutcome',
                'statusMix',
                'outreachByChannel' => ['email' => ['completed', 'inProgress', 'notStarted', 'total'], 'call'],
                'niche',
                'followThrough' => ['leadsContacted', 'consultations', 'confirmedClients', 'missedFollowUps', 'outreachCompletionPct', 'targetsPending'],
                'pendingFollowUps' => ['counts' => ['overdue', 'today', 'upcoming'], 'rows'],
            ]])
            ->json('data');

        // The qualification funnel narrows monotonically (each stage is a subset of the one before it).
        $stages = $data['funnel']['stages'];
        $this->assertSame(['Added', 'Contacted', 'Interested', 'Consultation', 'Confirmed'], array_column($stages, 'label'));
        $this->assertGreaterThanOrEqual($stages[1]['value'], $stages[0]['value']); // Added ≥ Contacted
        $this->assertGreaterThanOrEqual($stages[2]['value'], $stages[1]['value']); // Contacted ≥ Interested
        $this->assertGreaterThanOrEqual($stages[3]['value'], $stages[2]['value']); // Interested ≥ Consultation

        // No goals are ever invented — the honest "targets pending" contract.
        $this->assertTrue($data['followThrough']['targetsPending']);
    }

    /** @param array<string, mixed> $attrs */
    private function consultation(User $bd, Lead $lead, ?string $outcome, string $status = 'completed'): PlannerEvent
    {
        return PlannerEvent::factory()->assignedTo($bd)->create([
            'event_type' => 'consultation',
            'status' => $status,
            'outcome' => $outcome,
            'lead_id' => $lead->id,
            'created_by' => $bd->id,
        ]);
    }

    /**
     * 🔒 The frozen consultation rule (PDR-002 §4b), asserted straight off the enum: EVERY outcome is
     * exercised and expected to count if — and only if — `ConsultationOutcome::attended()` says so.
     * Written this way deliberately: a hardcoded expectation list could drift from the enum, which is
     * exactly the class of bug this milestone exists to eliminate.
     */
    public function test_every_outcome_counts_exactly_as_the_enum_declares(): void
    {
        $admin = $this->user('crm_admin');
        $bd = $this->user('crm_bd');

        $expected = 0;
        foreach (ConsultationOutcome::cases() as $case) {
            $lead = Lead::factory()->create(['assigned_to' => $bd->id, 'created_by' => $bd->id]);
            $this->consultation($bd, $lead, $case->value);
            if ($case->attended()) {
                $expected++;
            }
        }

        Sanctum::actingAs($admin);
        $data = $this->getJson('/api/v1/crm/dashboard/reports-analytics')->assertOk()->json('data');

        $this->assertSame($expected, $data['consultationsHeld']);
        $this->assertSame($expected, $data['followThrough']['consultations']);
        // Sanity: the enum really does split the set, so this test is not vacuously true.
        $this->assertGreaterThan(0, $expected);
        $this->assertLessThan(count(ConsultationOutcome::cases()), $expected);
    }

    /** A lead counts ONCE however many consultations it underwent. */
    public function test_consultations_held_counts_unique_leads(): void
    {
        $admin = $this->user('crm_admin');
        $bd = $this->user('crm_bd');
        $lead = Lead::factory()->create(['assigned_to' => $bd->id, 'created_by' => $bd->id]);

        $this->consultation($bd, $lead, 'successful');
        $this->consultation($bd, $lead, 'successful');
        $this->consultation($bd, $lead, 'not_interested');

        Sanctum::actingAs($admin);
        $this->assertSame(1, $this->getJson('/api/v1/crm/dashboard/reports-analytics')->json('data.consultationsHeld'));
    }

    /** A consultation that has not concluded is not a consultation held, whatever its outcome column says. */
    public function test_an_unfinished_consultation_is_never_counted(): void
    {
        $admin = $this->user('crm_admin');
        $bd = $this->user('crm_bd');
        $lead = Lead::factory()->create(['assigned_to' => $bd->id, 'created_by' => $bd->id]);

        $this->consultation($bd, $lead, 'successful', status: 'scheduled');

        Sanctum::actingAs($admin);
        $this->assertSame(0, $this->getJson('/api/v1/crm/dashboard/reports-analytics')->json('data.consultationsHeld'));
    }

    /**
     * `outcome = null` is AWAITING BUSINESS COMPLETION, never attendance. It is excluded from the KPI
     * and surfaced instead as an operational queue — counted as EVENTS, since each row needs a result.
     */
    public function test_null_outcome_is_excluded_from_the_kpi_and_surfaced_as_a_queue(): void
    {
        $admin = $this->user('crm_admin');
        $bd = $this->user('crm_bd');
        $lead = Lead::factory()->create(['assigned_to' => $bd->id, 'created_by' => $bd->id]);

        // Two unresolved consultations on ONE lead — e.g. both completed by a connected call.
        $this->consultation($bd, $lead, null);
        $this->consultation($bd, $lead, null);

        Sanctum::actingAs($admin);
        $data = $this->getJson('/api/v1/crm/dashboard/reports-analytics')->assertOk()->json('data');

        $this->assertSame(0, $data['consultationsHeld']);
        $this->assertSame(2, $data['consultationsAwaitingOutcome']);   // events, not distinct leads
        $this->assertSame(2, $data['followThrough']['consultationsAwaitingOutcome']);
    }

    /** Consultation metrics ride the same ownership scope seam as every other figure on the page. */
    public function test_consultation_metrics_are_scope_aware(): void
    {
        $admin = $this->user('crm_admin');
        $bd = $this->user('crm_bd');
        $other = $this->user('crm_bd');

        foreach ([$bd, $other] as $owner) {
            $lead = Lead::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id]);
            $this->consultation($owner, $lead, 'successful');
        }

        Sanctum::actingAs($admin);
        $this->assertSame(2, $this->getJson('/api/v1/crm/dashboard/reports-analytics')->json('data.consultationsHeld'));
        $this->assertSame(
            1,
            $this->getJson("/api/v1/crm/dashboard/reports-analytics?user_id={$bd->id}")->json('data.consultationsHeld'),
        );
    }

    /** The funnel is untouched by this milestone — it still measures SCHEDULED slots. */
    public function test_the_funnel_consultation_stage_is_unchanged(): void
    {
        $admin = $this->user('crm_admin');
        $bd = $this->user('crm_bd');

        // A held consultation on a lead that is NOT in a scheduled-call state: it lifts the KPI but
        // must leave the funnel stage alone.
        $lead = Lead::factory()->create([
            'assigned_to' => $bd->id, 'created_by' => $bd->id,
            'status' => 'interested', 'cold_calling' => 'called', 'email_outreach' => 'sent',
        ]);
        $this->consultation($bd, $lead, 'successful');

        Sanctum::actingAs($admin);
        $data = $this->getJson('/api/v1/crm/dashboard/reports-analytics')->assertOk()->json('data');

        $stage = collect($data['funnel']['stages'])->firstWhere('key', 'consultation');
        $this->assertSame(0, $stage['value']);        // scheduled-slot rule → still zero
        $this->assertSame(1, $data['consultationsHeld']); // held rule → one
    }

    public function test_admin_drill_matches_the_bds_own_scope(): void
    {
        $admin = $this->user('crm_admin');
        $bd = $this->user('crm_bd');
        $other = $this->user('crm_bd');

        Lead::factory()->count(3)->create(['assigned_to' => $bd->id, 'created_by' => $bd->id]);
        Lead::factory()->count(5)->create(['assigned_to' => $other->id, 'created_by' => $other->id]);

        Sanctum::actingAs($bd);
        $self = $this->getJson('/api/v1/crm/dashboard/reports-analytics')->assertOk()->json('data.funnel.stages');

        Sanctum::actingAs($admin);
        $drill = $this->getJson("/api/v1/crm/dashboard/reports-analytics?user_id={$bd->id}")->assertOk()->json('data.funnel.stages');
        $this->assertSame($self, $drill);
        $this->assertSame(3, $drill[0]['value']); // Added = only the BD's own leads, not the tenant total

        $all = $this->getJson('/api/v1/crm/dashboard/reports-analytics')->assertOk()->json('data.funnel.stages');
        $this->assertSame(8, $all[0]['value']); // 3 + 5 tenant-wide
    }

    /**
     * The overdue/today/upcoming buckets are half-open [start, end) windows computed on the Asia/Manila
     * day boundary, so they PARTITION the pending set: an event landing exactly on midnight belongs to
     * `upcoming` only, and the three counts always sum to the total (never double-counting a boundary row).
     */
    public function test_pending_follow_up_buckets_partition_on_the_manila_day_boundary(): void
    {
        $admin = $this->user('crm_admin');
        $bd = $this->user('crm_bd');
        $midnight = now('Asia/Manila')->startOfDay()->addDay(); // exactly the today→upcoming boundary

        foreach ([$midnight->copy()->subDays(2), $midnight->copy()->subHours(3), $midnight, $midnight->copy()->addDay()] as $startsAt) {
            PlannerEvent::factory()->assignedTo($bd)->create([
                'event_type' => 'follow-up',
                'status' => 'scheduled',
                'starts_at' => $startsAt->copy()->utc(),
            ]);
        }

        Sanctum::actingAs($admin);
        $counts = $this->getJson('/api/v1/crm/dashboard/reports-analytics')
            ->assertOk()
            ->json('data.pendingFollowUps.counts');

        $this->assertSame(1, $counts['overdue']);
        $this->assertSame(1, $counts['today']);
        $this->assertSame(2, $counts['upcoming']); // the midnight row counts here exactly once
        $this->assertSame(4, $counts['overdue'] + $counts['today'] + $counts['upcoming']);
    }

    /**
     * The row list is CAPPED while the counts are not — and the card renders both, so the cap has to
     * stay pinned. Without this, raising or dropping `limit()` changes what the UI claims with no
     * test failing: the header would keep saying 14 while the table silently showed a different
     * number of rows, or the payload would quietly become unbounded.
     */
    public function test_the_pending_follow_up_row_list_is_capped_at_ten(): void
    {
        $admin = $this->user('crm_admin');
        $bd = $this->user('crm_bd');
        $overdue = now('Asia/Manila')->startOfDay()->subDays(3)->utc();

        // Fourteen pending items — comfortably over the cap.
        PlannerEvent::factory()->count(14)->assignedTo($bd)->create([
            'event_type' => 'follow-up',
            'status' => 'scheduled',
            'starts_at' => $overdue,
        ]);

        Sanctum::actingAs($admin);
        $pending = $this->getJson('/api/v1/crm/dashboard/reports-analytics')
            ->assertOk()
            ->json('data.pendingFollowUps');

        $this->assertCount(10, $pending['rows'], 'the row list is capped');
        // The counts must still describe the WHOLE set — that gap is what the card renders as
        // "+N more not shown", and it only works while the counts stay uncapped.
        $this->assertSame(14, $pending['counts']['overdue']);
        $this->assertSame(
            14,
            $pending['counts']['overdue'] + $pending['counts']['today'] + $pending['counts']['upcoming'],
        );
    }

    /**
     * Each row carries the lead it is owed to, so the card can send the reader there. Null when the
     * event has no lead — that is not a defect, it is the case the card falls back to the Planner
     * board for, and it must stay expressible rather than being coerced to 0.
     */
    public function test_pending_follow_up_rows_carry_their_lead_id(): void
    {
        $admin = $this->user('crm_admin');
        $bd = $this->user('crm_bd');
        $lead = Lead::factory()->create(['assigned_to' => $bd->id]);
        $overdue = now('Asia/Manila')->startOfDay()->subDays(2)->utc();

        PlannerEvent::factory()->assignedTo($bd)->create([
            'event_type' => 'follow-up',
            'status' => 'scheduled',
            'starts_at' => $overdue,
            'lead_id' => $lead->id,
        ]);
        PlannerEvent::factory()->assignedTo($bd)->create([
            'event_type' => 'follow-up',
            'status' => 'scheduled',
            'starts_at' => $overdue,
            'lead_id' => null,
        ]);

        Sanctum::actingAs($admin);
        $rows = $this->getJson('/api/v1/crm/dashboard/reports-analytics')
            ->assertOk()
            ->json('data.pendingFollowUps.rows');

        $leadIds = array_column($rows, 'leadId');
        $this->assertContains($lead->id, $leadIds, 'a lead-linked row exposes its lead');
        $this->assertContains(null, $leadIds, 'a lead-less row exposes null, not 0');
    }

    /** At or below the cap the two agree exactly, so the card shows no "+N more" row. */
    public function test_counts_and_rows_agree_when_under_the_cap(): void
    {
        $admin = $this->user('crm_admin');
        $bd = $this->user('crm_bd');

        PlannerEvent::factory()->count(4)->assignedTo($bd)->create([
            'event_type' => 'follow-up',
            'status' => 'scheduled',
            'starts_at' => now('Asia/Manila')->startOfDay()->subDays(2)->utc(),
        ]);

        Sanctum::actingAs($admin);
        $pending = $this->getJson('/api/v1/crm/dashboard/reports-analytics')
            ->assertOk()
            ->json('data.pendingFollowUps');

        $total = $pending['counts']['overdue'] + $pending['counts']['today'] + $pending['counts']['upcoming'];
        $this->assertCount(4, $pending['rows']);
        $this->assertSame(4, $total);
        $this->assertSame($total, count($pending['rows']), 'no rows are hidden below the cap');
    }

    public function test_bd_cannot_drill_into_another_bd(): void
    {
        $bd = $this->user('crm_bd');
        $other = $this->user('crm_bd');
        Sanctum::actingAs($bd);

        $this->getJson("/api/v1/crm/dashboard/reports-analytics?user_id={$other->id}")->assertForbidden();
        $this->getJson('/api/v1/crm/dashboard/reports-analytics')->assertOk();
    }
}
