<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Jobs\SendScheduledEmailsJob;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * BR-NQ-01 — a BD who qualifies a prospect out of Nearby Clients gets one first-touch Planner Todo,
 * so the lead surfaces in Outreach instead of landing owned, scored, and invisible.
 *
 * This is the ONE place the system originates work rather than reconciling intent the BD already
 * expressed, so the tests here are as much about what must NOT happen as what must. Three properties
 * carry the risk:
 *
 *  1. **Exactly one event.** A prospect that arrives already `cold_calling = 'scheduled'` can reach
 *     both creators in the same request; the dedupe must collapse that to one.
 *  2. **No email, ever.** Qualification must not become a send trigger. Asserted structurally — no
 *     `crm_emails` row exists, and the scheduler job that would deliver one dispatches nothing.
 *  3. **Nothing else moves.** The Todo must not drag scoring, engagement, status, or ownership
 *     behaviour along with it.
 */
class NearbyQualificationFirstTouchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        // LeadFactory resolves `created_by` to a random EXISTING user and falls back to id 1, which
        // violates the FK when no users exist yet. Prospects below are created unowned, so one user
        // must exist first. It owns nothing and affects no assertion.
        $this->user('crm_admin');
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);

        return $user;
    }

    /**
     * A Nearby prospect as the discovery pipeline actually produces one: unowned (the pool is shared),
     * `cold_calling = 'not-called'` (IngestDiscoveredLeadsRequest rejects the field and the column
     * defaults to it), unqualified, unscored.
     */
    private function prospect(string $coldCalling = 'not-called'): Lead
    {
        return Lead::factory()->create([
            'assigned_to' => null,
            'qualified_at' => null,
            'source' => 'nearby',
            'cold_calling' => $coldCalling,
            'email_outreach' => 'none',
            'status' => 'new',
            // LeadFactory randomises `last_activity` across the last 30 days, which makes the
            // Recent Activity (+5) / No Activity (−10) signals fire non-deterministically and would
            // make the score comparison below flap. Discovery never sets it anyway.
            'last_activity' => null,
        ]);
    }

    /** @return Collection<int, PlannerEvent> */
    private function eventsFor(Lead $lead): Collection
    {
        return PlannerEvent::query()->where('lead_id', $lead->id)->get();
    }

    private function qualify(User $actor, Lead $lead): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->postJson("/api/v1/crm/leads/{$lead->id}/qualify");
    }

    // ---------------------------------------------------------------- the new behaviour

    public function test_bd_qualification_creates_exactly_one_planner_todo(): void
    {
        $bd = $this->user('crm_bd');
        $prospect = $this->prospect();

        $this->qualify($bd, $prospect)->assertCreated();

        $this->assertCount(1, $this->eventsFor($prospect));
    }

    public function test_the_todo_carries_the_approved_shape(): void
    {
        $bd = $this->user('crm_bd');
        $prospect = $this->prospect();

        $this->qualify($bd, $prospect)->assertCreated();

        $event = $this->eventsFor($prospect)->first();

        $this->assertSame('follow-up', $event->event_type);
        $this->assertSame('todo', $event->status);
        $this->assertNull($event->starts_at, 'Qualification carries no deadline.');
        $this->assertSame('nearby', $event->source);
        $this->assertSame($bd->id, $event->assigned_to, 'The qualifying BD owns the work.');
    }

    /**
     * The point of the whole slice: Outreach is derived from pending follow-up/email events
     * (features/follow-ups/lib/outreach-pending.ts), so this is what "appears in Outreach" means
     * server-side.
     */
    public function test_the_qualified_lead_now_has_pending_outreach_work(): void
    {
        $bd = $this->user('crm_bd');
        $prospect = $this->prospect();

        $this->qualify($bd, $prospect)->assertCreated();

        $pending = PlannerEvent::query()
            ->where('lead_id', $prospect->id)
            ->whereIn('event_type', ['follow-up', 'email'])
            ->whereIn('status', ['todo', 'scheduled', 'in_progress'])
            ->where('assigned_to', $bd->id)
            ->count();

        $this->assertSame(1, $pending);
    }

    // ---------------------------------------------------------------- no email, ever

    public function test_qualification_creates_no_email_row(): void
    {
        $bd = $this->user('crm_bd');
        $prospect = $this->prospect();

        $this->qualify($bd, $prospect)->assertCreated();

        $this->assertSame(0, Email::query()->count());
        $this->assertDatabaseCount('crm_emails', 0);
    }

    /**
     * The structural proof that a Todo cannot become a send: the scheduler selects only EXISTING
     * `crm_emails` rows, so with none there is nothing to claim and no ScheduledEmailDue is raised.
     */
    public function test_the_scheduled_email_job_dispatches_nothing_after_qualification(): void
    {
        $bd = $this->user('crm_bd');
        $prospect = $this->prospect();

        $this->qualify($bd, $prospect)->assertCreated();

        Event::fake();
        app(SendScheduledEmailsJob::class)->handle(app(\App\Modules\Crm\Services\Outreach\ScheduledEmailService::class));

        Event::assertNotDispatched(\App\Modules\Crm\Events\ScheduledEmailDue::class);
        $this->assertSame(0, Email::query()->count());
    }

    // ---------------------------------------------------------------- idempotency & dedupe

    public function test_re_qualification_is_refused_and_creates_no_second_todo(): void
    {
        $bd = $this->user('crm_bd');
        $prospect = $this->prospect();

        $this->qualify($bd, $prospect)->assertCreated();
        $this->qualify($bd, $prospect->fresh())->assertStatus(409);

        $this->assertCount(1, $this->eventsFor($prospect));
    }

    public function test_existing_open_follow_up_work_suppresses_the_first_touch(): void
    {
        $bd = $this->user('crm_bd');
        $prospect = $this->prospect();

        // Pre-existing open work — the same condition the cold-call dedupe has always honoured.
        PlannerEvent::create([
            'title' => 'Pre-existing work',
            'event_type' => 'follow-up',
            'status' => 'todo',
            'source' => 'manual',
            'lead_id' => $prospect->id,
            'assigned_to' => $bd->id,
            'created_by' => $bd->id,
        ]);

        $this->qualify($bd, $prospect)->assertCreated();

        $this->assertCount(1, $this->eventsFor($prospect), 'No duplicate work.');
    }

    /**
     * Both creators can fire in one request when a prospect somehow arrives already `scheduled`.
     * Ordering + the shared dedupe must collapse that to exactly one event — this is the assertion
     * most likely to catch a regression in that ordering.
     */
    public function test_an_already_scheduled_prospect_still_gets_only_one_event(): void
    {
        $bd = $this->user('crm_bd');
        $prospect = $this->prospect('scheduled');

        $this->qualify($bd, $prospect)->assertCreated();

        $events = $this->eventsFor($prospect);
        $this->assertCount(1, $events);
        $this->assertSame($bd->id, $events->first()->assigned_to);
    }

    public function test_a_rejected_qualification_creates_nothing(): void
    {
        $bd = $this->user('crm_bd');
        // `source` null → already an active CRM lead → 422.
        $active = Lead::factory()->create(['source' => null, 'qualified_at' => null, 'assigned_to' => null]);

        $this->qualify($bd, $active)->assertStatus(422);

        $this->assertCount(0, $this->eventsFor($active));
    }

    // ---------------------------------------------------------------- BD-only

    public function test_admin_qualification_creates_no_todo_but_still_succeeds(): void
    {
        $admin = $this->user('crm_admin');
        $prospect = $this->prospect();

        // Existing authorization is preserved verbatim: crm_admin holds crm.leads.qualify and the
        // policy is permission-only, so the request succeeds exactly as it does today.
        $this->qualify($admin, $prospect)->assertCreated();

        $this->assertCount(0, $this->eventsFor($prospect), 'Planner is a BD workspace.');
        $this->assertSame($admin->id, $prospect->fresh()->assigned_to, 'Ownership behaviour unchanged.');
    }

    public function test_super_admin_qualification_creates_no_todo(): void
    {
        $owner = $this->user('super_admin');
        $prospect = $this->prospect();

        $this->qualify($owner, $prospect)->assertCreated();

        $this->assertCount(0, $this->eventsFor($prospect));
    }

    // ---------------------------------------------------------------- frozen logic untouched

    public function test_the_todo_moves_no_lead_column(): void
    {
        $bd = $this->user('crm_bd');
        $prospect = $this->prospect();

        $this->qualify($bd, $prospect)->assertCreated();

        $lead = DB::table('crm_leads')->where('id', $prospect->id)->first();

        // Qualification's own writes, unchanged.
        $this->assertNotNull($lead->qualified_at);
        $this->assertSame($bd->id, (int) $lead->assigned_to);
        // Everything the Todo must NOT touch.
        $this->assertSame('not-called', $lead->cold_calling, 'Consultation stage + call_scheduled signal.');
        $this->assertSame('none', $lead->email_outreach, 'ENGAGED + Email Opened signal.');
        $this->assertSame('new', $lead->status, 'Sales lifecycle stays a manual BD decision.');
        $this->assertNull($lead->last_activity, 'Recency signals unmoved.');
    }

    /**
     * The score is computed once by qualification itself (LeadObserver on the `qualified_at`
     * transition). Creating a Planner row must not add to, or re-run, that.
     */
    public function test_the_score_is_the_same_with_and_without_the_todo(): void
    {
        $bd = $this->user('crm_bd');
        $admin = $this->user('crm_admin');

        // Every scoring input pinned identically, so the ONLY difference between the two leads is
        // whether a Todo was minted. LeadFactory randomises website_status and the name fields, all
        // of which feed Fit signals.
        $pinned = [
            'website_status' => 'no-website',
            'city' => 'Cebu City',
            'business_name' => 'Identical Co',
            'contact_name' => 'Maria Santos',
        ];

        $withTodo = $this->prospect();
        $withTodo->update($pinned);
        $withoutTodo = $this->prospect();
        $withoutTodo->update($pinned);

        $this->qualify($bd, $withTodo)->assertCreated();       // mints a Todo
        $this->qualify($admin, $withoutTodo)->assertCreated(); // mints none

        $this->assertCount(1, $this->eventsFor($withTodo));
        $this->assertCount(0, $this->eventsFor($withoutTodo));
        $this->assertSame(
            (int) $withoutTodo->fresh()->score,
            (int) $withTodo->fresh()->score,
            'The Todo contributes nothing to the Lead Score.',
        );
    }

    /** A BD with no CRM access at all must still be refused — RBAC is untouched by this slice. */
    public function test_qualification_authorization_is_unchanged(): void
    {
        $stranger = User::factory()->create(['status' => 'active']); // no roles
        $prospect = $this->prospect();

        Sanctum::actingAs($stranger);
        $this->postJson("/api/v1/crm/leads/{$prospect->id}/qualify")->assertForbidden();

        $this->assertCount(0, $this->eventsFor($prospect));
        $this->assertNull($prospect->fresh()->qualified_at);
    }
}
