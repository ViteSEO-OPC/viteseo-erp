<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\BdPerformanceSnapshot;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use App\Modules\Crm\Notifications\LeadsReassignedNotification;
use App\Modules\Crm\Services\CrmAccessService;
use App\Modules\Crm\Services\Kpi\BdKpiService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Slice 2C — the resignation handover.
 *
 * The rule under test is the one {@see \App\Modules\Crm\Services\Planner\PlannerOwnershipTransfer}
 * already froze: **open work follows ownership; completed work keeps the BD who performed it.** A
 * resignation moves current custody and outstanding responsibility, and moves nothing that records
 * what somebody actually did.
 */
class LeadReassignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function bd(string $name): User
    {
        $user = User::factory()->create(['status' => 'active', 'name' => $name]);
        $user->assignRole('crm_bd');

        return $user->fresh();
    }

    private function admin(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('crm_admin');

        return $user;
    }

    // --- What moves ----------------------------------------------------------

    public function test_a_departing_bds_leads_and_open_work_move_to_the_replacement(): void
    {
        $jane = $this->bd('Jane Aranda');
        $newOwner = $this->bd('BD One');
        $leads = Lead::factory()->count(3)->create(['assigned_to' => $jane->id]);
        $openOnLead = PlannerEvent::factory()->create(['assigned_to' => $jane->id, 'lead_id' => $leads[0]->id, 'status' => 'todo']);
        $standalone = PlannerEvent::factory()->create(['assigned_to' => $jane->id, 'lead_id' => null, 'status' => 'todo']);
        app(CrmAccessService::class)->suspend($jane);

        $this->actingAs($this->admin())
            ->postJson("/api/v1/crm/users/{$jane->id}/reassign-leads", ['to_user_id' => $newOwner->id])
            ->assertOk()
            ->assertJsonPath('data.leads_reassigned', 3)
            ->assertJsonPath('data.standalone_events_transferred', 1);

        $this->assertSame(0, Lead::query()->where('assigned_to', $jane->id)->count());
        $this->assertSame(3, Lead::query()->where('assigned_to', $newOwner->id)->count());
        $this->assertSame($newOwner->id, $openOnLead->fresh()->assigned_to);
        // Standalone work is keyed to no lead and would otherwise be stranded with a suspended account.
        $this->assertSame($newOwner->id, $standalone->fresh()->assigned_to);
    }

    // --- What stays ----------------------------------------------------------

    public function test_completed_planner_work_stays_with_the_bd_who_performed_it(): void
    {
        $jane = $this->bd('Jane Aranda');
        $newOwner = $this->bd('BD One');
        $lead = Lead::factory()->create(['assigned_to' => $jane->id]);
        $done = PlannerEvent::factory()->create(['assigned_to' => $jane->id, 'lead_id' => $lead->id, 'status' => 'completed']);
        $doneStandalone = PlannerEvent::factory()->create(['assigned_to' => $jane->id, 'lead_id' => null, 'status' => 'completed']);

        $this->actingAs($this->admin())
            ->postJson("/api/v1/crm/users/{$jane->id}/reassign-leads", ['to_user_id' => $newOwner->id])
            ->assertOk();

        $this->assertSame($jane->id, $done->fresh()->assigned_to);
        $this->assertSame($jane->id, $doneStandalone->fresh()->assigned_to);
    }

    public function test_provenance_columns_are_never_rewritten(): void
    {
        $jane = $this->bd('Jane Aranda');
        $newOwner = $this->bd('BD One');
        $lead = Lead::factory()->create([
            'assigned_to' => $jane->id,
            'created_by' => $jane->id,
            'qualified_by' => $jane->id,
        ]);
        Activity::query()->create([
            'subject_type' => $lead->getMorphClass(),
            'subject_id' => $lead->id,
            'causer_id' => $jane->id,
            'event' => 'lead.updated',
            'description' => 'Jane worked it',
            'properties' => [],
        ]);

        $this->actingAs($this->admin())
            ->postJson("/api/v1/crm/users/{$jane->id}/reassign-leads", ['to_user_id' => $newOwner->id])
            ->assertOk();

        $fresh = $lead->fresh();
        $this->assertSame($newOwner->id, $fresh->assigned_to, 'custody moves');
        $this->assertSame($jane->id, $fresh->created_by, 'provenance does not');
        $this->assertSame($jane->id, $fresh->qualified_by);
        $this->assertSame(1, Activity::query()->where('causer_id', $jane->id)->where('event', 'lead.updated')->count());
    }

    public function test_janes_activity_credit_survives_the_handover(): void
    {
        $jane = $this->bd('Jane Aranda');
        $newOwner = $this->bd('BD One');
        $lead = Lead::factory()->create(['assigned_to' => $jane->id]);
        Activity::query()->create([
            'subject_type' => $lead->getMorphClass(),
            'subject_id' => $lead->id,
            'causer_id' => $jane->id,
            'event' => 'lead.updated',
            'description' => 'Jane worked it',
            'properties' => [],
        ]);

        $this->actingAs($this->admin())
            ->postJson("/api/v1/crm/users/{$jane->id}/reassign-leads", ['to_user_id' => $newOwner->id])
            ->assertOk();

        $kpi = app(BdKpiService::class);
        // M-06 is causer-scoped (Slice 2B), so effort follows the performer, not the portfolio.
        $this->assertSame(1, $kpi->forUser($jane->fresh())->metricValue('M-06'));
        $this->assertSame(0, $kpi->forUser($newOwner->fresh())->metricValue('M-06'));
    }

    public function test_a_snapshot_is_captured_when_the_portfolio_is_drained(): void
    {
        $jane = $this->bd('Jane Aranda');
        $this->bd('Peer One');
        $newOwner = $this->bd('BD One');
        Lead::factory()->count(5)->create(['assigned_to' => $jane->id]);

        // Deliberately NOT suspended first — the drain itself must protect the record.
        $this->actingAs($this->admin())
            ->postJson("/api/v1/crm/users/{$jane->id}/reassign-leads", ['to_user_id' => $newOwner->id])
            ->assertOk();

        $snapshot = BdPerformanceSnapshot::query()->where('user_id', $jane->id)->firstOrFail();
        $this->assertSame(BdPerformanceSnapshot::REASON_REASSIGNMENT, $snapshot->reason);
        $this->assertSame(5, $snapshot->metrics['kpi'][0]['value'], 'M-01 must record the portfolio she held');
        $this->assertSame(0, app(BdKpiService::class)->forUser($jane->fresh())->metricValue('M-01'));
    }

    // --- Audit trail and notification ----------------------------------------

    public function test_every_lead_gets_its_own_assignment_activity(): void
    {
        $jane = $this->bd('Jane Aranda');
        $newOwner = $this->bd('BD One');
        Lead::factory()->count(4)->create(['assigned_to' => $jane->id]);

        $this->actingAs($this->admin())
            ->postJson("/api/v1/crm/users/{$jane->id}/reassign-leads", ['to_user_id' => $newOwner->id])
            ->assertOk();

        // The granular audit is preserved even though the announcement is collapsed.
        $this->assertSame(4, Activity::query()->where('event', 'lead.assigned')->count());
    }

    public function test_the_recipient_gets_one_summary_notification_not_one_per_lead(): void
    {
        Notification::fake();
        $jane = $this->bd('Jane Aranda');
        $newOwner = $this->bd('BD One');
        Lead::factory()->count(6)->create(['assigned_to' => $jane->id]);

        $this->actingAs($this->admin())
            ->postJson("/api/v1/crm/users/{$jane->id}/reassign-leads", ['to_user_id' => $newOwner->id])
            ->assertOk();

        Notification::assertSentToTimes($newOwner, LeadsReassignedNotification::class, 1);
    }

    /**
     * The one that matters to a human: not that a notification was dispatched, but that the row an
     * Admin or BD actually sees in the Notification Center says what happened.
     *
     * Renders through the real endpoint, so it exercises NotificationResource → NotificationType →
     * presenter. A dotted `data->type` or a payload carrying pre-rendered strings both resolve to no
     * presenter, and this asserts the difference: title, message and link, or a bare humanised type
     * string with no message and nothing to click.
     */
    public function test_the_recipient_sees_a_readable_notification_in_the_notification_center(): void
    {
        $jane = $this->bd('Jane Aranda');
        $newOwner = $this->bd('BD One');
        $admin = $this->admin();
        $admin->forceFill(['name' => 'Rea Villanueva'])->save();
        Lead::factory()->count(13)->create(['assigned_to' => $jane->id]);
        PlannerEvent::factory()->create(['assigned_to' => $jane->id, 'lead_id' => null, 'status' => 'todo']);

        $this->actingAs($admin)
            ->postJson("/api/v1/crm/users/{$jane->id}/reassign-leads", ['to_user_id' => $newOwner->id])
            ->assertOk();

        $this->actingAs($newOwner)
            ->getJson('/api/v1/crm/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'leads_reassigned')
            ->assertJsonPath('data.0.title', '13 leads reassigned to you')
            ->assertJsonPath(
                'data.0.message',
                'Rea Villanueva reassigned 13 leads from Jane Aranda to you, along with 1 open Planner item.'
            )
            ->assertJsonPath('data.0.link', '/zerem/leads');
    }

    public function test_a_handover_with_no_planner_work_omits_the_planner_clause(): void
    {
        $jane = $this->bd('Jane Aranda');
        $newOwner = $this->bd('BD One');
        $admin = $this->admin();
        $admin->forceFill(['name' => 'Rea Villanueva'])->save();
        Lead::factory()->create(['assigned_to' => $jane->id]);

        $this->actingAs($admin)
            ->postJson("/api/v1/crm/users/{$jane->id}/reassign-leads", ['to_user_id' => $newOwner->id])
            ->assertOk();

        $this->actingAs($newOwner)
            ->getJson('/api/v1/crm/notifications')
            ->assertOk()
            // Singular, and no trailing ", along with 0 open Planner items".
            ->assertJsonPath('data.0.title', '1 lead reassigned to you')
            ->assertJsonPath('data.0.message', 'Rea Villanueva reassigned 1 lead from Jane Aranda to you.');
    }

    // --- Destination guard ---------------------------------------------------

    public function test_a_suspended_bd_cannot_receive_a_portfolio(): void
    {
        $jane = $this->bd('Jane Aranda');
        $alsoSuspended = $this->bd('BD One');
        Lead::factory()->count(2)->create(['assigned_to' => $jane->id]);
        app(CrmAccessService::class)->suspend($alsoSuspended);

        $this->actingAs($this->admin())
            ->postJson("/api/v1/crm/users/{$jane->id}/reassign-leads", ['to_user_id' => $alsoSuspended->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('to_user_id');

        $this->assertSame(2, Lead::query()->where('assigned_to', $jane->id)->count());
    }

    public function test_a_portfolio_cannot_be_reassigned_to_its_own_owner(): void
    {
        $jane = $this->bd('Jane Aranda');
        Lead::factory()->create(['assigned_to' => $jane->id]);

        $this->actingAs($this->admin())
            ->postJson("/api/v1/crm/users/{$jane->id}/reassign-leads", ['to_user_id' => $jane->id])
            ->assertStatus(422);
    }

    public function test_a_bd_cannot_reassign_a_portfolio(): void
    {
        $jane = $this->bd('Jane Aranda');
        $newOwner = $this->bd('BD One');

        $this->actingAs($this->bd('Nosy BD'))
            ->postJson("/api/v1/crm/users/{$jane->id}/reassign-leads", ['to_user_id' => $newOwner->id])
            ->assertForbidden();
    }

    // --- Isolation -----------------------------------------------------------

    public function test_reassignment_writes_nothing_to_the_shared_user_rows(): void
    {
        $jane = $this->bd('Jane Aranda');
        $newOwner = $this->bd('BD One');
        Lead::factory()->count(2)->create(['assigned_to' => $jane->id]);
        $janeBefore = DB::table('users')->where('id', $jane->id)->first();
        $ownerBefore = DB::table('users')->where('id', $newOwner->id)->first();

        $this->actingAs($this->admin())
            ->postJson("/api/v1/crm/users/{$jane->id}/reassign-leads", ['to_user_id' => $newOwner->id])
            ->assertOk();

        $this->assertEquals($janeBefore, DB::table('users')->where('id', $jane->id)->first());
        $this->assertEquals($ownerBefore, DB::table('users')->where('id', $newOwner->id)->first());
    }

    public function test_reassignment_never_touches_crm_roles(): void
    {
        $jane = $this->bd('Jane Aranda');
        $jane->assignRole('hr_employee');
        $newOwner = $this->bd('BD One');
        Lead::factory()->create(['assigned_to' => $jane->id]);

        $this->actingAs($this->admin())
            ->postJson("/api/v1/crm/users/{$jane->id}/reassign-leads", ['to_user_id' => $newOwner->id])
            ->assertOk();

        $names = $jane->fresh()->getRoleNames()->all();
        sort($names);
        $this->assertSame(['crm_bd', 'hr_employee'], $names);
    }

    // --- Team Accounts payload -----------------------------------------------

    public function test_the_team_accounts_list_carries_the_portfolio_size(): void
    {
        $jane = $this->bd('Jane Aranda');
        Lead::factory()->count(7)->create(['assigned_to' => $jane->id]);

        $this->actingAs($this->admin())
            ->getJson('/api/v1/crm/users')
            ->assertOk()
            ->assertJsonFragment(['id' => $jane->id, 'lead_count' => 7]);
    }

    public function test_the_leads_payload_flags_a_suspended_owner(): void
    {
        $jane = $this->bd('Jane Aranda');
        Lead::factory()->create(['assigned_to' => $jane->id]);
        app(CrmAccessService::class)->suspend($jane);

        $this->actingAs($this->admin())
            ->getJson('/api/v1/crm/leads')
            ->assertOk()
            ->assertJsonPath('data.0.assignedTo.suspended', true);
    }
}
