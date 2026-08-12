<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Planner ownership-visibility boundary: **a BD sees exactly the events assigned to them.**
 *
 * This pins the narrowing of PlannerEvent::scopeVisibleTo, which previously also admitted every event
 * linked to a lead the BD owns. That branch caused one defect with two faces — cards stamped with
 * another BD's name, and Planner KPIs charging a single row to both the assignee and the lead owner.
 *
 * The completed case is the one that most needs a test rather than a comment: completed work is never
 * transferred (it records who performed it), so a new lead owner must NOT inherit sight of the
 * previous BD's completed events. That history stays reachable on the LEAD's timeline, which is
 * lead-scoped by Activity::scopeVisibleTo — a different question with a different scope.
 *
 * Both halves of the boundary are covered: the LIST scope (`scopeVisibleTo`) and the SINGLE-RECORD
 * policy path (`isVisibleTo`, via PlannerEventPolicy). They must never diverge, or a BD could reach
 * by id something the list would never show them.
 */
class PlannerVisibilityTest extends TestCase
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

    /** `cold_calling` pinned — LeadFactory randomises it and 'scheduled' auto-mints an extra event. */
    private function lead(?User $owner): Lead
    {
        return Lead::factory()->create([
            'assigned_to' => $owner?->id,
            'cold_calling' => 'not-called',
        ]);
    }

    private function event(?User $owner, ?Lead $lead, string $status = 'todo'): PlannerEvent
    {
        return PlannerEvent::create([
            'title' => 'Planner work',
            'event_type' => 'follow-up',
            'status' => $status,
            'lead_id' => $lead?->id,
            'assigned_to' => $owner?->id,
            'created_by' => $owner?->id,
        ]);
    }

    /** @return list<string> planner event ids the user's Planner returns */
    private function visibleIds(User $user): array
    {
        Sanctum::actingAs($user);

        return $this->getJson('/api/v1/crm/planner-events')
            ->assertOk()
            ->json('data.*.id');
    }

    // ---------------------------------------------------------------- the BD boundary

    public function test_a_bd_sees_their_own_assigned_events(): void
    {
        $bd = $this->user('crm_bd');
        $lead = $this->lead($bd);
        $own = $this->event($bd, $lead);

        $this->assertContains((string) $own->id, $this->visibleIds($bd));
    }

    public function test_a_bd_does_not_see_another_bds_event_merely_because_they_own_the_lead(): void
    {
        $owner = $this->user('crm_bd');       // owns the lead
        $other = $this->user('crm_bd');       // owns the event
        $lead = $this->lead($owner);
        $theirs = $this->event($other, $lead);

        // THE NARROWING. Before this change the lead-ownership branch admitted this row and the Board
        // rendered it with $other's name on the card.
        $this->assertNotContains((string) $theirs->id, $this->visibleIds($owner));

        // The single-record path must agree — otherwise the list hides it while GET by id serves it.
        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/crm/planner-events/{$theirs->id}")->assertForbidden();
    }

    public function test_completed_events_owned_by_another_bd_are_hidden_too(): void
    {
        $owner = $this->user('crm_bd');
        $performer = $this->user('crm_bd');
        $lead = $this->lead($owner);
        // Completed work is never transferred — it records who performed it — so the lead's new owner
        // must not inherit sight of it. It remains on the lead's Activity timeline.
        $done = $this->event($performer, $lead, 'completed');

        $this->assertNotContains((string) $done->id, $this->visibleIds($owner));
        $this->assertContains((string) $done->id, $this->visibleIds($performer));
    }

    public function test_a_standalone_event_assigned_to_the_bd_stays_visible(): void
    {
        $bd = $this->user('crm_bd');
        // No lead at all: under the old rule only the assigned_to branch could admit this, and it
        // must keep working now that it is the ONLY branch.
        $standalone = $this->event($bd, null);

        $this->assertContains((string) $standalone->id, $this->visibleIds($bd));
    }

    public function test_a_bd_does_not_see_an_event_on_a_lead_they_do_not_own(): void
    {
        $bd = $this->user('crm_bd');
        $other = $this->user('crm_bd');
        $foreign = $this->event($other, $this->lead($other));

        $this->assertNotContains((string) $foreign->id, $this->visibleIds($bd));
    }

    public function test_an_event_assigned_to_the_bd_on_someone_elses_lead_is_still_theirs(): void
    {
        $bd = $this->user('crm_bd');
        $other = $this->user('crm_bd');
        // Assignment wins over lead ownership in BOTH directions: this is the BD's work even though
        // the lead belongs to someone else (an Admin can deliberately book one BD onto another's lead).
        $assigned = $this->event($bd, $this->lead($other));

        $this->assertContains((string) $assigned->id, $this->visibleIds($bd));
        $this->assertNotContains((string) $assigned->id, $this->visibleIds($other));
    }

    // ---------------------------------------------------------------- management roles unchanged

    public function test_admin_visibility_is_unchanged_and_unrestricted(): void
    {
        $admin = $this->user('crm_admin');
        $bdA = $this->user('crm_bd');
        $bdB = $this->user('crm_bd');
        $a = $this->event($bdA, $this->lead($bdA));
        $b = $this->event($bdB, $this->lead($bdB), 'completed');
        $standalone = $this->event($bdA, null);

        $visible = $this->visibleIds($admin);

        foreach ([$a, $b, $standalone] as $event) {
            $this->assertContains((string) $event->id, $visible);
        }

        Sanctum::actingAs($admin);
        $this->getJson("/api/v1/crm/planner-events/{$b->id}")->assertOk();
    }

    public function test_super_admin_visibility_is_unchanged_and_unrestricted(): void
    {
        $superAdmin = $this->user('super_admin');
        $bd = $this->user('crm_bd');
        $theirs = $this->event($bd, $this->lead($bd));
        $completed = $this->event($bd, $this->lead($bd), 'completed');

        $visible = $this->visibleIds($superAdmin);

        $this->assertContains((string) $theirs->id, $visible);
        $this->assertContains((string) $completed->id, $visible);
    }

    // ---------------------------------------------------------------- scope/policy parity

    public function test_the_scope_and_the_row_level_check_agree(): void
    {
        $bd = $this->user('crm_bd');
        $other = $this->user('crm_bd');
        $lead = $this->lead($bd);
        $own = $this->event($bd, $lead);
        $foreign = $this->event($other, $lead);

        // scopeVisibleTo (list) and isVisibleTo (single record) must never disagree — the policy
        // composes both, so a divergence is a reachable-by-id hole.
        $scoped = PlannerEvent::query()->visibleTo($bd)->pluck('id')->all();

        $this->assertContains($own->id, $scoped);
        $this->assertTrue($own->isVisibleTo($bd));

        $this->assertNotContains($foreign->id, $scoped);
        $this->assertFalse($foreign->fresh()->isVisibleTo($bd));
    }
}
