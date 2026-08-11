<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The BD Dashboard's "Follow-ups Due" KPI (`GET /dashboard/kpis` → `followUpsDue`).
 *
 * The card used to be a hardcoded placeholder. It is now the WHOLE scoped pending follow-up set —
 * the same frozen definition the reports page's overdue/today/upcoming buckets partition, reached
 * through one shared builder so the two surfaces cannot drift:
 *
 *   event_type IN (follow-up, email, call, meeting)  AND  starts_at IS NOT NULL
 *     AND  status != 'completed'
 *   scoped by PlannerEvent::visibleTo, day boundary in Asia/Manila
 *
 * The type list is PlannerEvent::FOLLOW_UP_WORK_TYPES, reached through `followUpWork()`. It is the
 * whole CATEGORY, not the literal string — Lead Details stores the BD's channel structurally, so
 * matching `'follow-up'` alone would silently drop most of this KPI's population.
 *
 * The PO ruling that an outbound email is NOT a follow-up is why this counts Planner events only —
 * `crm_emails` is deliberately not a source here.
 */
class DashboardFollowUpsDueTest extends TestCase
{
    use RefreshDatabase;

    /** Manila is UTC+8, so 08:00 UTC is today 16:00 in Manila — comfortably inside the same day. */
    private const MIDDAY_UTC = '2026-08-03 08:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(Carbon::parse(self::MIDDAY_UTC));
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);

        return $user;
    }

    /** A follow-up on a lead owned by $owner, with the given start and status. */
    private function followUp(User $owner, ?string $startsAt, string $status = 'todo', array $overrides = []): PlannerEvent
    {
        $lead = Lead::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id]);

        return PlannerEvent::create([
            'title' => 'Follow-up',
            'event_type' => 'follow-up',
            'status' => $status,
            'starts_at' => $startsAt,
            'lead_id' => $lead->id,
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
            ...$overrides,
        ]);
    }

    private function followUpsDue(): int
    {
        return (int) $this->getJson('/api/v1/crm/dashboard/kpis')->assertOk()->json('data.followUpsDue');
    }

    // ── Shape ───────────────────────────────────────────────────────────────────────────────────

    /** The card reads this field; if it disappears the KPI silently reverts to a placeholder. */
    public function test_the_kpi_response_exposes_the_follow_ups_due_field(): void
    {
        Sanctum::actingAs($this->user('crm_bd'));

        $this->getJson('/api/v1/crm/dashboard/kpis')
            ->assertOk()
            ->assertJsonStructure(['data' => ['totalLeads', 'highPriority', 'interested', 'followUpsDue', 'statusCounts']]);
    }

    /** No hardcoded value survives: an empty pipeline reports a real 0, not the old placeholder. */
    public function test_a_bd_with_no_follow_ups_sees_zero(): void
    {
        Sanctum::actingAs($this->user('crm_bd'));

        $this->assertSame(0, $this->followUpsDue());
    }

    // ── The three buckets all count ──────────────────────────────────────────────────────────────

    public function test_an_overdue_follow_up_is_counted(): void
    {
        $bd = $this->user('crm_bd');
        $this->followUp($bd, '2026-07-30 02:00:00'); // days before today (Manila)
        Sanctum::actingAs($bd);

        $this->assertSame(1, $this->followUpsDue());
    }

    public function test_a_follow_up_due_today_is_counted(): void
    {
        $bd = $this->user('crm_bd');
        $this->followUp($bd, '2026-08-03 09:00:00'); // same Manila day as MIDDAY_UTC
        Sanctum::actingAs($bd);

        $this->assertSame(1, $this->followUpsDue());
    }

    public function test_an_upcoming_follow_up_is_counted(): void
    {
        $bd = $this->user('crm_bd');
        $this->followUp($bd, '2026-08-10 02:00:00');
        Sanctum::actingAs($bd);

        $this->assertSame(1, $this->followUpsDue());
    }

    /**
     * THE invariant tying this card to the reports page: Follow-ups Due is exactly the set those
     * three buckets partition, so it must equal their sum for the same user at the same instant.
     */
    public function test_the_total_equals_overdue_plus_today_plus_upcoming(): void
    {
        $bd = $this->user('crm_bd');
        $this->followUp($bd, '2026-07-28 02:00:00'); // overdue
        $this->followUp($bd, '2026-07-29 02:00:00'); // overdue
        $this->followUp($bd, '2026-08-03 09:00:00'); // today
        $this->followUp($bd, '2026-08-09 02:00:00'); // upcoming
        Sanctum::actingAs($bd);

        $counts = $this->getJson('/api/v1/crm/dashboard/reports-analytics')
            ->assertOk()
            ->json('data.pendingFollowUps.counts');

        $this->assertSame([2, 1, 1], [$counts['overdue'], $counts['today'], $counts['upcoming']]);
        $this->assertSame(
            $counts['overdue'] + $counts['today'] + $counts['upcoming'],
            $this->followUpsDue(),
        );
    }

    // ── Exclusions ──────────────────────────────────────────────────────────────────────────────

    public function test_completed_follow_ups_are_excluded(): void
    {
        $bd = $this->user('crm_bd');
        $this->followUp($bd, '2026-08-03 09:00:00', 'completed');
        Sanctum::actingAs($bd);

        $this->assertSame(0, $this->followUpsDue());
    }

    /** An undated follow-up cannot be "due" — it has no day to fall on. */
    public function test_undated_follow_ups_are_excluded(): void
    {
        $bd = $this->user('crm_bd');
        $this->followUp($bd, null);
        Sanctum::actingAs($bd);

        $this->assertSame(0, $this->followUpsDue());
    }

    /**
     * The card counts FOLLOW-UP WORK ({@see PlannerEvent::FOLLOW_UP_WORK_TYPES}), not the bare
     * `follow-up` literal — an Email, Call or Meeting task that is due is work that is due.
     *
     * CHANGED DELIBERATELY (PO-approved Model B, Phase 1). This test previously asserted that a
     * meeting and a call were excluded, which was only correct while Lead Details discarded the BD's
     * channel choice and stored every follow-up as `follow-up`.
     */
    public function test_every_channel_of_follow_up_work_counts(): void
    {
        $bd = $this->user('crm_bd');
        $this->followUp($bd, '2026-08-03 09:00:00', 'todo', ['event_type' => 'meeting']);
        $this->followUp($bd, '2026-08-03 09:00:00', 'todo', ['event_type' => 'call']);
        $this->followUp($bd, '2026-08-03 09:00:00', 'todo', ['event_type' => 'email']);
        Sanctum::actingAs($bd);

        $this->assertSame(3, $this->followUpsDue());
    }

    /** `task` and `consultation` carry their own meaning and stay out of the count. */
    public function test_tasks_and_consultations_are_excluded(): void
    {
        $bd = $this->user('crm_bd');
        $this->followUp($bd, '2026-08-03 09:00:00', 'todo', ['event_type' => 'task']);
        $this->followUp($bd, '2026-08-03 09:00:00', 'todo', ['event_type' => 'consultation']);
        Sanctum::actingAs($bd);

        $this->assertSame(0, $this->followUpsDue());
    }

    // ── Ownership / RBAC ────────────────────────────────────────────────────────────────────────

    /** A BD must never see another BD's queue. */
    public function test_a_bd_sees_only_their_own_follow_ups(): void
    {
        $mine = $this->user('crm_bd');
        $theirs = $this->user('crm_bd');
        $this->followUp($mine, '2026-08-03 09:00:00');
        $this->followUp($theirs, '2026-08-03 09:00:00');
        $this->followUp($theirs, '2026-07-30 02:00:00');
        Sanctum::actingAs($mine);

        $this->assertSame(1, $this->followUpsDue());
    }

    /** Admin and Super Admin see the whole CRM — the same scope the other KPIs on this endpoint use. */
    public function test_admin_and_super_admin_see_every_bd_follow_up(): void
    {
        $a = $this->user('crm_bd');
        $b = $this->user('crm_bd');
        $this->followUp($a, '2026-08-03 09:00:00');
        $this->followUp($b, '2026-07-30 02:00:00');

        Sanctum::actingAs($this->user('crm_admin'));
        $this->assertSame(2, $this->followUpsDue());

        Sanctum::actingAs($this->user('super_admin'));
        $this->assertSame(2, $this->followUpsDue());
    }

    // ── Timezone boundary ───────────────────────────────────────────────────────────────────────

    /**
     * The Manila day boundary, not UTC's. At 2026-08-03 08:00 UTC it is already 16:00 on the 3rd in
     * Manila, so an event at 2026-08-03 20:00 UTC (04:00 on the 4th, Manila) is TOMORROW — upcoming,
     * not today. Either way it is pending, so the total counts it: this asserts the boundary is
     * applied consistently across both surfaces rather than one of them slipping to UTC.
     */
    public function test_the_day_boundary_is_asia_manila_across_both_surfaces(): void
    {
        $bd = $this->user('crm_bd');
        $this->followUp($bd, '2026-08-03 20:00:00'); // 2026-08-04 04:00 Manila → upcoming
        Sanctum::actingAs($bd);

        $counts = $this->getJson('/api/v1/crm/dashboard/reports-analytics')->assertOk()
            ->json('data.pendingFollowUps.counts');

        $this->assertSame([0, 0, 1], [$counts['overdue'], $counts['today'], $counts['upcoming']]);
        $this->assertSame(1, $this->followUpsDue());
    }

    /** An event exactly on Manila midnight belongs to one bucket only, and is still counted once. */
    public function test_an_event_on_the_manila_midnight_boundary_is_counted_once(): void
    {
        $bd = $this->user('crm_bd');
        $this->followUp($bd, '2026-08-03 16:00:00'); // exactly 2026-08-04 00:00 Manila
        Sanctum::actingAs($bd);

        $counts = $this->getJson('/api/v1/crm/dashboard/reports-analytics')->assertOk()
            ->json('data.pendingFollowUps.counts');

        $this->assertSame(1, $counts['upcoming']);
        $this->assertSame(0, $counts['today']);
        $this->assertSame(1, $this->followUpsDue());
    }
}
