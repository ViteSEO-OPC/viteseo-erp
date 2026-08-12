<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The cold-call auto follow-up never produces an OWNERLESS Planner event.
 *
 * FollowUpScheduler::create() takes the event's owner from the lead, so an unassigned lead would mint
 * an event with `assigned_to = NULL`: work nobody is accountable for, invisible to every BD
 * (PlannerEvent::scopeVisibleTo matches on `assigned_to`), and permanent — PlannerOwnershipTransfer
 * declines to move an event whose lead had no previous owner, and the backfill command only considers
 * events that already have one. The fix prevents the row rather than trying to reclaim it.
 *
 * These tests cover both directions: an owned lead still gets its follow-up, and an unowned one gets
 * nothing at all — at creation, on the transition into `scheduled`, and after a later assignment.
 */
class ColdCallFollowUpOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        // LeadFactory resolves `created_by` to a random EXISTING user and falls back to id 1, which
        // violates the FK when no users exist yet. Several tests below create a lead with no owner and
        // no other actor, so one has to exist first. It owns nothing, so it affects no assertion.
        $this->user('crm_admin');
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);

        return $user;
    }

    /** LeadFactory leaves `assigned_to` null by default and randomises `cold_calling`; both are pinned. */
    private function lead(?User $owner, string $coldCalling = 'not-called'): Lead
    {
        return Lead::factory()->create([
            'assigned_to' => $owner?->id,
            'cold_calling' => $coldCalling,
        ]);
    }

    /** @return Collection<int, PlannerEvent> */
    private function eventsFor(Lead $lead): Collection
    {
        return PlannerEvent::query()->where('lead_id', $lead->id)->get();
    }

    /**
     * Only the cold-call follow-ups.
     *
     * Needed wherever a test COMPLETES one: that transition dispatches FollowUpCompleted, and
     * EscalateUnansweredFollowUp answers it by creating a separate `task` ("No response — follow up
     * with X"). That task is correct existing behaviour and must not be mistaken for a duplicate.
     *
     * @return Collection<int, PlannerEvent>
     */
    private function followUpsFor(Lead $lead): Collection
    {
        return PlannerEvent::query()
            ->where('lead_id', $lead->id)
            ->where('event_type', 'follow-up')
            ->get();
    }

    // ---------------------------------------------------------------- owned lead: unchanged

    public function test_an_assigned_lead_marked_scheduled_gets_a_follow_up_for_that_bd(): void
    {
        $bd = $this->user('crm_bd');
        $lead = $this->lead($bd);

        $lead->update(['cold_calling' => 'scheduled']);

        $events = $this->eventsFor($lead);
        $this->assertCount(1, $events);
        $this->assertSame($bd->id, $events->first()->assigned_to);
        $this->assertSame('follow-up', $events->first()->event_type);
    }

    public function test_a_lead_created_already_scheduled_and_owned_gets_its_follow_up(): void
    {
        $bd = $this->user('crm_bd');
        // The creation-time branch of LeadObserver, not the transition branch.
        $lead = $this->lead($bd, 'scheduled');

        $events = $this->eventsFor($lead);
        $this->assertCount(1, $events);
        $this->assertSame($bd->id, $events->first()->assigned_to);
    }

    // ---------------------------------------------------------------- unowned lead: nothing

    public function test_an_unassigned_lead_marked_scheduled_creates_no_planner_event(): void
    {
        $lead = $this->lead(null);

        $lead->update(['cold_calling' => 'scheduled']);

        $this->assertCount(0, $this->eventsFor($lead));
        $this->assertDatabaseMissing('crm_planner_events', ['lead_id' => $lead->id]);
    }

    public function test_a_lead_created_unassigned_and_already_scheduled_creates_no_planner_event(): void
    {
        $lead = $this->lead(null, 'scheduled');

        $this->assertCount(0, $this->eventsFor($lead));
    }

    public function test_no_ownerless_planner_event_can_be_created_by_this_path(): void
    {
        $this->lead(null, 'scheduled');
        $this->lead(null)->update(['cold_calling' => 'scheduled']);

        // The invariant the whole fix exists to hold.
        $this->assertSame(0, PlannerEvent::query()->whereNull('assigned_to')->count());
    }

    // ---------------------------------------------------------------- later assignment

    public function test_assigning_an_unowned_scheduled_lead_materializes_exactly_one_follow_up(): void
    {
        $admin = $this->user('crm_admin');
        $bd = $this->user('crm_bd');
        $lead = $this->lead(null);
        $lead->update(['cold_calling' => 'scheduled']);
        $this->assertCount(0, $this->eventsFor($lead), 'precondition: nothing was created while unowned');

        Sanctum::actingAs($admin);
        $this->patchJson("/api/v1/crm/leads/{$lead->id}/assign", ['assigned_to' => $bd->id])->assertOk();

        // `cold_calling = scheduled` is a STATE. It could not become work while the lead was unowned;
        // giving it an owner is the moment that work becomes real.
        $events = $this->eventsFor($lead);
        $this->assertCount(1, $events);
        $this->assertSame($bd->id, $events->first()->assigned_to);
        $this->assertSame('follow-up', $events->first()->event_type);
        // The invariant from the guard half of this slice still holds.
        $this->assertSame(0, PlannerEvent::query()->whereNull('assigned_to')->count());
    }

    public function test_assigning_an_unowned_lead_that_is_not_scheduled_creates_nothing(): void
    {
        $admin = $this->user('crm_admin');
        $bd = $this->user('crm_bd');
        $lead = $this->lead(null);   // cold_calling = 'not-called'

        Sanctum::actingAs($admin);
        $this->patchJson("/api/v1/crm/leads/{$lead->id}/assign", ['assigned_to' => $bd->id])->assertOk();

        // Assignment materializes state that already existed; it never invents work.
        $this->assertCount(0, $this->eventsFor($lead));
    }

    public function test_repeated_reassignment_never_stacks_duplicates(): void
    {
        $admin = $this->user('crm_admin');
        $first = $this->user('crm_bd');
        $second = $this->user('crm_bd');
        $lead = $this->lead(null);
        $lead->update(['cold_calling' => 'scheduled']);

        Sanctum::actingAs($admin);
        $this->patchJson("/api/v1/crm/leads/{$lead->id}/assign", ['assigned_to' => $first->id])->assertOk();
        $this->patchJson("/api/v1/crm/leads/{$lead->id}/assign", ['assigned_to' => $second->id])->assertOk();
        $this->patchJson("/api/v1/crm/leads/{$lead->id}/assign", ['assigned_to' => $first->id])->assertOk();

        // Materialized once on the FIRST acquisition; every later hop is a transfer, not a creation.
        $events = $this->eventsFor($lead);
        $this->assertCount(1, $events);
        $this->assertSame($first->id, $events->first()->assigned_to);
    }

    public function test_reassignment_does_not_recreate_a_completed_cold_call_follow_up(): void
    {
        $admin = $this->user('crm_admin');
        $from = $this->user('crm_bd');
        $to = $this->user('crm_bd');
        $lead = $this->lead($from);
        $lead->update(['cold_calling' => 'scheduled']);
        // The BD did the work. The scheduler's dedupe only sees OPEN work, so without the
        // previously-null-owner gate this reassignment would mint a fresh follow-up.
        $this->followUpsFor($lead)->sole()->update(['status' => 'completed']);

        Sanctum::actingAs($admin);
        $this->patchJson("/api/v1/crm/leads/{$lead->id}/assign", ['assigned_to' => $to->id])->assertOk();

        // Scoped to follow-ups: completing one also spawns an escalation `task`, which is separate,
        // pre-existing behaviour and not a duplicate of the cold-call follow-up.
        $followUps = $this->followUpsFor($lead);
        $this->assertCount(1, $followUps, 'reassignment must transfer work, never invent it');
        $this->assertSame('completed', $followUps->first()->status);
        $this->assertSame($from->id, $followUps->first()->assigned_to, 'completed work keeps its performer');
    }

    public function test_existing_open_work_is_transferred_not_duplicated(): void
    {
        $admin = $this->user('crm_admin');
        $from = $this->user('crm_bd');
        $to = $this->user('crm_bd');
        $lead = $this->lead($from);
        $lead->update(['cold_calling' => 'scheduled']);
        $event = $this->eventsFor($lead)->sole();

        Sanctum::actingAs($admin);
        $this->patchJson("/api/v1/crm/leads/{$lead->id}/assign", ['assigned_to' => $to->id])->assertOk();

        $this->assertCount(1, $this->eventsFor($lead));
        $this->assertSame($to->id, $event->fresh()->assigned_to);
    }

    public function test_an_admin_booked_event_suppresses_materialization(): void
    {
        $admin = $this->user('crm_admin');
        $specialist = $this->user('crm_bd');
        $bd = $this->user('crm_bd');
        $lead = $this->lead(null);
        $lead->update(['cold_calling' => 'scheduled']);

        // Admin books the work on the unowned lead before assigning it.
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/crm/planner-events', [
            'title' => 'Already planned',
            'event_type' => 'follow-up',
            'lead_id' => $lead->id,
            'assigned_to' => $specialist->id,
        ])->assertCreated();

        $this->patchJson("/api/v1/crm/leads/{$lead->id}/assign", ['assigned_to' => $bd->id])->assertOk();

        // The scheduler's existing dedupe still applies — open follow-up work already exists.
        $this->assertCount(1, $this->eventsFor($lead));
    }

    // ---------------------------------------------------------------- qualification / self-assign

    public function test_qualifying_an_unowned_scheduled_prospect_materializes_one_follow_up(): void
    {
        $bd = $this->user('crm_bd');
        $prospect = Lead::factory()->create([
            'assigned_to' => null,
            'cold_calling' => 'scheduled',
            'source' => 'nearby',
            'qualified_at' => null,
        ]);
        $this->assertCount(0, $this->eventsFor($prospect), 'precondition: unowned prospects create nothing');

        Sanctum::actingAs($bd);
        $this->postJson("/api/v1/crm/leads/{$prospect->id}/qualify")->assertCreated();

        // Both ownership-acquisition paths must behave identically — a prospect must not carry
        // different Planner work depending on whether an Admin assigned it or a BD claimed it.
        $events = $this->eventsFor($prospect);
        $this->assertCount(1, $events);
        $this->assertSame($bd->id, $events->first()->assigned_to);
    }

    public function test_qualifying_an_unowned_unscheduled_prospect_creates_nothing(): void
    {
        $bd = $this->user('crm_bd');
        $prospect = Lead::factory()->create([
            'assigned_to' => null,
            'cold_calling' => 'not-called',
            'source' => 'nearby',
            'qualified_at' => null,
        ]);

        Sanctum::actingAs($bd);
        $this->postJson("/api/v1/crm/leads/{$prospect->id}/qualify")->assertCreated();

        $this->assertCount(0, $this->eventsFor($prospect));
    }

    public function test_qualifying_an_already_owned_prospect_transfers_without_duplicating(): void
    {
        $previous = $this->user('crm_bd');
        $claimer = $this->user('crm_bd');
        $prospect = Lead::factory()->create([
            'assigned_to' => $previous->id,
            'cold_calling' => 'scheduled',
            'source' => 'nearby',
            'qualified_at' => null,
        ]);
        $event = $this->eventsFor($prospect)->sole();

        Sanctum::actingAs($claimer);
        $this->postJson("/api/v1/crm/leads/{$prospect->id}/qualify")->assertCreated();

        // Previous owner was NOT null → transfer only, no materialization.
        $this->assertCount(1, $this->eventsFor($prospect));
        $this->assertSame($claimer->id, $event->fresh()->assigned_to);
    }

    public function test_the_reassignment_transfer_is_untouched_for_leads_that_do_have_work(): void
    {
        $admin = $this->user('crm_admin');
        $from = $this->user('crm_bd');
        $to = $this->user('crm_bd');
        $lead = $this->lead($from);
        $lead->update(['cold_calling' => 'scheduled']);
        $event = $this->eventsFor($lead)->sole();

        Sanctum::actingAs($admin);
        $this->patchJson("/api/v1/crm/leads/{$lead->id}/assign", ['assigned_to' => $to->id])->assertOk();

        // Slice 1 behaviour must be exactly as before — the guard only affects unowned leads.
        $this->assertSame($to->id, $event->fresh()->assigned_to);
    }

    // ---------------------------------------------------------------- dedupe rule unchanged

    public function test_an_owned_lead_still_gets_only_one_auto_follow_up(): void
    {
        $bd = $this->user('crm_bd');
        $lead = $this->lead($bd);

        $lead->update(['cold_calling' => 'scheduled']);
        $lead->update(['cold_calling' => 'not-called']);
        $lead->update(['cold_calling' => 'scheduled']);

        // The existing idempotence guard, unaffected by the new ownership check.
        $this->assertCount(1, $this->eventsFor($lead));
    }
}
