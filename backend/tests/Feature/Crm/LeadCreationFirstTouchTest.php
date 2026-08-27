<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use App\Modules\Crm\Services\LeadCreator;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BR-LC-01 (Business-Process-Mapping §4b) — a BD-created lead mints exactly one first-touch Todo.
 *
 * The Create Lead Wizard has never carried a Cold Calling field, so a wizard-created lead is born at
 * the column default `not-called` and reaches none of the pre-existing triggers. It was therefore
 * owned, scored and active yet absent from Outreach. `FollowUpScheduler::scheduleOnLeadCreation()`
 * originates that work directly, WITHOUT touching `cold_calling` — which is a scoring input and a
 * dashboard Consultation signal, and so must not be used as a shortcut.
 *
 * THE RULE IS KEYED TO THE OWNER, NOT THE CREATOR: any manually created lead assigned to a BD gets
 * exactly one first touch, whether the BD created it or an Admin/Super Admin did on their behalf.
 * That is why this method has no actor-role gate, unlike scheduleFirstTouch() — see its docblock.
 *
 * These tests pin the new behaviour AND the safeguards it reuses unchanged: ownership, prospect
 * exclusion, and dedupe. Bulk import is deliberately out of scope here and keeps its own behaviour.
 */
class LeadCreationFirstTouchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);

        return $user;
    }

    /** @return Collection<int, PlannerEvent> */
    private function eventsFor(Lead $lead): Collection
    {
        return PlannerEvent::query()->where('lead_id', $lead->id)->get();
    }

    /** The manual POST /leads path, exercised through the service the controller delegates to. */
    private function createLead(User $creator, array $attributes = []): Lead
    {
        return app(LeadCreator::class)->create([
            'business_name' => 'Acme Corp',
            'contact_name' => 'Alex Reyes',
            ...$attributes,
        ], $creator);
    }

    public function test_a_bd_created_lead_gets_exactly_one_follow_up(): void
    {
        $bd = $this->user('crm_bd');

        $lead = $this->createLead($bd);
        $events = $this->eventsFor($lead);

        $this->assertCount(1, $events, 'A BD-created lead owes exactly one first touch.');
        $this->assertSame('follow-up', $events->first()->event_type);
        $this->assertSame($bd->id, $events->first()->assigned_to, 'The creating BD owns the work.');
    }

    public function test_the_todo_carries_the_approved_shape(): void
    {
        $bd = $this->user('crm_bd');

        $event = $this->eventsFor($this->createLead($bd))->first();

        $this->assertSame('follow-up', $event->event_type);
        $this->assertSame('todo', $event->status);
        $this->assertNull($event->starts_at, 'Creation carries no deadline.');
        $this->assertSame('outreach', $event->source);
    }

    public function test_the_lead_now_has_pending_outreach_work(): void
    {
        $bd = $this->user('crm_bd');
        $lead = $this->createLead($bd);

        // The Outreach inbox definition: a follow-up/email event that is not completed.
        $pending = PlannerEvent::query()
            ->where('lead_id', $lead->id)
            ->whereIn('event_type', ['follow-up', 'email'])
            ->where('status', '!=', 'completed')
            ->count();

        $this->assertSame(1, $pending, 'The new lead must be visible in Outreach.');
    }

    public function test_it_moves_no_lead_column(): void
    {
        $bd = $this->user('crm_bd');

        $lead = $this->createLead($bd)->fresh();

        // cold_calling is a scoring input AND the dashboard Consultation signal — the whole reason
        // it was rejected as the mechanism. It must stay at the column default.
        $this->assertSame('not-called', $lead->cold_calling);
    }

    public function test_an_unowned_lead_gets_none(): void
    {
        $admin = $this->user('crm_admin');

        // An Admin creator is NOT force-assigned to self, so omitting assigned_to leaves it unowned.
        // (POST /leads requires assigned_to for a non-BD; this exercises the service-level guard.)
        $lead = $this->createLead($admin);

        $this->assertNull($lead->assigned_to);
        $this->assertCount(0, $this->eventsFor($lead), 'Ownerless work must never be minted.');
    }

    /**
     * The rule is keyed to the OWNER, not the creator. An Admin creating a lead on a BD's behalf must
     * leave that BD exactly one first touch — gating on the actor would silently deny it.
     */
    public function test_an_admin_created_lead_assigned_to_a_bd_gets_exactly_one(): void
    {
        $admin = $this->user('crm_admin');
        $bd = $this->user('crm_bd');

        $lead = $this->createLead($admin, ['assigned_to' => $bd->id]);
        $events = $this->eventsFor($lead);

        $this->assertCount(1, $events, 'An Admin-created, BD-owned lead still owes a first touch.');
        $this->assertSame($bd->id, $events->first()->assigned_to, 'The OWNING BD holds the work, not the Admin creator.');
        $this->assertSame('todo', $events->first()->status);
        $this->assertNull($events->first()->starts_at);
        $this->assertSame('outreach', $events->first()->source);
    }

    /** Same rule for Super Admin — the actor's role is irrelevant; the lead's owner decides. */
    public function test_a_super_admin_created_lead_assigned_to_a_bd_gets_exactly_one(): void
    {
        $superAdmin = $this->user('super_admin');
        $bd = $this->user('crm_bd');

        $lead = $this->createLead($superAdmin, ['assigned_to' => $bd->id]);
        $events = $this->eventsFor($lead);

        $this->assertCount(1, $events);
        $this->assertSame($bd->id, $events->first()->assigned_to);
    }

    /** The creator is never the owner of the work unless they also own the lead. */
    public function test_the_admin_creator_receives_no_work_of_their_own(): void
    {
        $admin = $this->user('crm_admin');
        $bd = $this->user('crm_bd');

        $this->createLead($admin, ['assigned_to' => $bd->id]);

        $this->assertSame(
            0,
            PlannerEvent::query()->where('assigned_to', $admin->id)->count(),
            'An Admin must never be handed Planner work — /zerem/planner is a BD workspace.',
        );
    }

    public function test_a_lead_created_already_scheduled_still_gets_exactly_one(): void
    {
        $bd = $this->user('crm_bd');

        // LeadObserver::created mints the cold-call follow-up first; the new call must decline on
        // the reused dedupe rather than stack a second event.
        $lead = $this->createLead($bd, ['cold_calling' => 'scheduled']);

        $this->assertCount(1, $this->eventsFor($lead), 'Dedupe must prevent a second event.');
    }

    public function test_a_prospect_gets_none(): void
    {
        $bd = $this->user('crm_bd');

        // source set + qualified_at null => a discovered prospect. Its first touch belongs to
        // BR-NQ-01 on qualification, not here.
        $lead = $this->createLead($bd, ['source' => 'nearby', 'qualified_at' => null]);

        $this->assertTrue($lead->isProspect());
        $this->assertCount(0, $this->eventsFor($lead), 'A prospect is BR-NQ-01 territory.');
    }

    public function test_manual_planner_task_creation_is_unaffected(): void
    {
        $bd = $this->user('crm_bd');
        $lead = $this->createLead($bd);

        // The existing manual path still works and still adds its own row alongside the first touch.
        PlannerEvent::create([
            'lead_id' => $lead->id,
            'event_type' => 'task',
            'title' => 'Prepare proposal',
            'status' => 'todo',
            'source' => 'manual',
            'assigned_to' => $bd->id,
            'created_by' => $bd->id,
        ]);

        $events = $this->eventsFor($lead);
        $this->assertCount(2, $events);
        $this->assertEqualsCanonicalizing(
            ['follow-up', 'task'],
            $events->pluck('event_type')->all(),
            'Manual task creation must remain available and independent.',
        );
    }
}
