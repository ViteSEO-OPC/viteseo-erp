<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Call;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use App\Modules\Crm\Policies\LeadPolicy;
use App\Modules\Crm\Rules\LinkableDeal;
use App\Modules\Crm\Rules\LinkableLead;
use App\Services\OrgOnboardingService;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Planner work may only be linked to a lead the caller may link work to (CEO audit crm-04).
 *
 * `lead_id` and `deal_id` used to be validated with a bare `exists:` rule that queried the table directly,
 * so a Business Development Manager could attach an event to a teammate's lead, an archived lead or another
 * organization's lead. The response then carried that lead's business, contact and industry, the observer
 * wrote a `planner-event.*` Activity onto that lead's timeline, and POST /planner-events/{id}/calls opened a
 * call against it that its agent could neither see nor conclude.
 *
 * Who may link is {@see LeadPolicy::link()}: the lead's owner, Admin/Super Admin,
 * anyone for an UNCLAIMED prospect (Nearby "Plan visit"), and the n8n automation account only for the
 * lead's current owner. Everything else fails with one message that reveals neither existence nor owner.
 */
class PlannerLeadLinkAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const EVENTS = '/api/v1/crm/planner-events';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);

        return $user;
    }

    /** A lead a teammate owns, carrying identifying fields that must never reach another BD. */
    private function teammatesLead(User $teammate): Lead
    {
        return Lead::factory()->assignedTo($teammate)->create([
            // Pinned, not random: an OWNED lead created with cold_calling = 'scheduled' makes LeadObserver create a
            // follow-up PlannerEvent (Cold Calling → Planner). That unrelated side effect would contaminate the exact
            // zero-event assertions below; the authorization under test does not read this field.
            'cold_calling' => 'not-called',
            'business_name' => 'Teammate Private Bakery',
            'contact_name' => 'Hidden Contact Person',
            'industry' => 'Hidden Industry Niche',
        ]);
    }

    /** A discovered Nearby prospect: unqualified, owner as given (null = unclaimed). */
    private function prospect(?User $owner = null): Lead
    {
        return Lead::factory()->create([
            'cold_calling' => 'not-called', // pinned — see teammatesLead()
            'source' => 'nearby',
            'qualified_at' => null,
            'qualified_by' => null,
            'assigned_to' => $owner?->id,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function meeting(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Client visit',
            'event_type' => 'meeting',
            'status' => 'scheduled',
            'starts_at' => now()->addDay()->toIso8601String(),
        ], $overrides);
    }

    private function activitiesOn(Lead $lead): int
    {
        return Activity::query()
            ->where('subject_type', (new Lead)->getMorphClass())
            ->where('subject_id', $lead->id)
            ->count();
    }

    private function assertLeadNotLeaked(TestResponse $response): void
    {
        foreach (['Teammate Private Bakery', 'Hidden Contact Person', 'Hidden Industry Niche'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $response->getContent());
        }
    }

    // ── Reproducing the audit: a BD reaching a teammate's lead ────────────────────────────────

    public function test_a_bd_cannot_link_an_event_to_a_teammates_lead(): void
    {
        $bd = $this->userWithRole('crm_bd');
        $lead = $this->teammatesLead($this->userWithRole('crm_bd'));
        $activitiesBefore = $this->activitiesOn($lead);
        Sanctum::actingAs($bd);

        $response = $this->postJson(self::EVENTS, $this->meeting(['lead_id' => $lead->id]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.lead_id.0', LinkableLead::MESSAGE);

        $this->assertLeadNotLeaked($response);
        $this->assertSame(0, PlannerEvent::query()->where('lead_id', $lead->id)->count());
        $this->assertSame($activitiesBefore, $this->activitiesOn($lead), 'no activity may be written on the teammate\'s lead');
    }

    public function test_a_consultation_or_follow_up_cannot_reach_a_teammates_lead_either(): void
    {
        $bd = $this->userWithRole('crm_bd');
        $lead = $this->teammatesLead($this->userWithRole('crm_bd'));
        $activitiesBefore = $this->activitiesOn($lead);
        Sanctum::actingAs($bd);

        foreach (['consultation', 'follow-up'] as $type) {
            $response = $this->postJson(self::EVENTS, $this->meeting(['event_type' => $type, 'lead_id' => $lead->id]))
                ->assertUnprocessable()
                ->assertJsonPath('errors.lead_id.0', LinkableLead::MESSAGE);
            $this->assertLeadNotLeaked($response);
        }

        $this->assertSame(0, PlannerEvent::query()->where('lead_id', $lead->id)->count());
        $this->assertSame($activitiesBefore, $this->activitiesOn($lead));
    }

    public function test_a_bd_links_an_event_to_their_own_lead(): void
    {
        $bd = $this->userWithRole('crm_bd');
        $lead = Lead::factory()->assignedTo($bd)->create();
        Sanctum::actingAs($bd);

        $this->postJson(self::EVENTS, $this->meeting(['lead_id' => $lead->id]))
            ->assertCreated()
            ->assertJsonPath('data.leadId', (string) $lead->id)
            ->assertJsonPath('data.lead.business', $lead->business_name);
    }

    // ── Nearby prospects: shared until claimed ───────────────────────────────────────────────

    public function test_a_bd_plans_a_visit_to_a_genuinely_unclaimed_prospect(): void
    {
        $bd = $this->userWithRole('crm_bd');
        $prospect = $this->prospect();
        Sanctum::actingAs($bd);

        $this->postJson(self::EVENTS, $this->meeting(['source' => 'nearby', 'lead_id' => $prospect->id]))
            ->assertCreated()
            ->assertJsonPath('data.leadId', (string) $prospect->id)
            ->assertJsonPath('data.source', 'nearby');
    }

    public function test_a_bd_cannot_plan_a_visit_to_a_prospect_that_belongs_to_another_bd(): void
    {
        $bd = $this->userWithRole('crm_bd');
        $other = $this->userWithRole('crm_bd');
        $assignedProspect = $this->prospect($other);
        $claimed = Lead::factory()->create([
            'source' => 'nearby', 'qualified_at' => now(), 'qualified_by' => $other->id, 'assigned_to' => $other->id,
            'cold_calling' => 'not-called', // pinned — see teammatesLead()
        ]);
        Sanctum::actingAs($bd);

        foreach ([$assignedProspect, $claimed] as $lead) {
            $this->postJson(self::EVENTS, $this->meeting(['source' => 'nearby', 'lead_id' => $lead->id]))
                ->assertUnprocessable()
                ->assertJsonPath('errors.lead_id.0', LinkableLead::MESSAGE);
        }

        $this->assertSame(0, PlannerEvent::query()->count());
    }

    // ── deal_id is judged through its lead ─────────────────────────────────────────────────

    public function test_a_bd_cannot_link_a_teammates_deal_but_can_link_their_own(): void
    {
        $bd = $this->userWithRole('crm_bd');
        $teammatesDeal = Deal::factory()->create(['lead_id' => $this->teammatesLead($this->userWithRole('crm_bd'))->id]);
        $ownDeal = Deal::factory()->create(['lead_id' => Lead::factory()->assignedTo($bd)->create()->id]);
        Sanctum::actingAs($bd);

        $this->postJson(self::EVENTS, $this->meeting(['deal_id' => $teammatesDeal->id]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.deal_id.0', LinkableDeal::MESSAGE);
        $this->assertSame(0, PlannerEvent::query()->where('deal_id', $teammatesDeal->id)->count());

        $this->postJson(self::EVENTS, $this->meeting(['deal_id' => $ownDeal->id]))
            ->assertCreated()
            ->assertJsonPath('data.dealId', (string) $ownDeal->id);
    }

    // ── Admin, archived, other organization ──────────────────────────────────────────────────

    public function test_an_admin_links_an_event_to_another_bds_lead_in_the_same_organization(): void
    {
        $bd = $this->userWithRole('crm_bd');
        $lead = Lead::factory()->assignedTo($bd)->create();
        Sanctum::actingAs($this->userWithRole('crm_admin'));

        $this->postJson(self::EVENTS, $this->meeting(['lead_id' => $lead->id, 'assigned_to' => $bd->id]))
            ->assertCreated()
            ->assertJsonPath('data.leadId', (string) $lead->id);
    }

    public function test_an_archived_lead_cannot_be_linked(): void
    {
        $bd = $this->userWithRole('crm_bd');
        $lead = Lead::factory()->assignedTo($bd)->create();
        $lead->delete();
        Sanctum::actingAs($bd);

        $this->postJson(self::EVENTS, $this->meeting(['lead_id' => $lead->id]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.lead_id.0', LinkableLead::MESSAGE);
    }

    /** Another organization's lead is refused with the SAME message as a teammate's: no existence oracle. */
    public function test_another_organizations_lead_is_refused_with_the_same_generic_message(): void
    {
        $bd = $this->userWithRole('crm_bd');
        $teammatesLead = $this->teammatesLead($this->userWithRole('crm_bd'));

        $otherOrg = app(OrgOnboardingService::class)->createOrg(
            'Other Org', 'Other Owner', 'owner-other@client.ph', 'password-123', ['crm' => 'full'],
        )['org'];
        $foreignLead = CurrentOrg::runAs($otherOrg->id, fn (): Lead => Lead::factory()->create([
            'business_name' => 'Foreign Tenant Co',
        ]));
        $this->assertNotSame($otherOrg->id, Lead::query()->find($teammatesLead->id)?->org_id);

        CurrentOrg::clear();
        Sanctum::actingAs($bd);

        $foreign = $this->postJson(self::EVENTS, $this->meeting(['lead_id' => $foreignLead->id]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.lead_id.0', LinkableLead::MESSAGE);
        $teammate = $this->postJson(self::EVENTS, $this->meeting(['lead_id' => $teammatesLead->id]))
            ->assertUnprocessable();

        $this->assertSame($teammate->json('errors.lead_id'), $foreign->json('errors.lead_id'));
        $this->assertStringNotContainsString('Foreign Tenant Co', (string) $foreign->getContent());
        $this->assertSame(0, PlannerEvent::withoutGlobalScopes()->where('lead_id', $foreignLead->id)->count());
    }

    // ── Update path ──────────────────────────────────────────────────────────────────────────

    public function test_relinking_an_event_to_a_teammates_lead_is_refused_and_leaves_it_unchanged(): void
    {
        $bd = $this->userWithRole('crm_bd');
        $ownLead = Lead::factory()->assignedTo($bd)->create();
        $teammatesLead = $this->teammatesLead($this->userWithRole('crm_bd'));
        $event = PlannerEvent::factory()->create([
            'event_type' => 'meeting', 'lead_id' => $ownLead->id, 'assigned_to' => $bd->id, 'created_by' => $bd->id,
        ]);
        $activitiesBefore = $this->activitiesOn($teammatesLead);
        Sanctum::actingAs($bd);

        $response = $this->patchJson(self::EVENTS."/{$event->id}", ['lead_id' => $teammatesLead->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.lead_id.0', LinkableLead::MESSAGE);

        $this->assertLeadNotLeaked($response);
        $this->assertSame($ownLead->id, $event->fresh()->lead_id);
        $this->assertSame($activitiesBefore, $this->activitiesOn($teammatesLead));
    }

    /**
     * The Planner form re-sends `lead_id` on every save, and a completed event keeps its lead after that lead
     * is reassigned. An unchanged link is not re-judged, so the owner can still edit the event.
     */
    public function test_saving_an_unchanged_link_after_the_lead_was_reassigned_still_succeeds(): void
    {
        $bd = $this->userWithRole('crm_bd');
        $lead = Lead::factory()->assignedTo($bd)->create();
        $event = PlannerEvent::factory()->create([
            'event_type' => 'meeting', 'status' => 'completed',
            'lead_id' => $lead->id, 'assigned_to' => $bd->id, 'created_by' => $bd->id,
        ]);
        // Reassigned elsewhere after the work was done (completed events are never transferred).
        DB::table('crm_leads')->where('id', $lead->id)->update(['assigned_to' => $this->userWithRole('crm_bd')->id]);
        Sanctum::actingAs($bd);

        $this->patchJson(self::EVENTS."/{$event->id}", ['title' => 'Visit notes added', 'lead_id' => $lead->id])
            ->assertOk()
            ->assertJsonPath('data.title', 'Visit notes added');

        $this->assertSame($lead->id, $event->fresh()->lead_id);
    }

    public function test_a_status_only_update_still_succeeds(): void
    {
        $bd = $this->userWithRole('crm_bd');
        $event = PlannerEvent::factory()->create([
            'event_type' => 'meeting', 'status' => 'scheduled',
            'lead_id' => Lead::factory()->assignedTo($bd)->create()->id, 'assigned_to' => $bd->id, 'created_by' => $bd->id,
        ]);
        Sanctum::actingAs($bd);

        $this->patchJson(self::EVENTS."/{$event->id}", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');
    }

    // ── P5: the orphan-call path ─────────────────────────────────────────────────────────────

    public function test_a_call_cannot_start_on_an_existing_event_linked_to_a_lead_the_caller_cannot_see(): void
    {
        $bd = $this->userWithRole('crm_bd');
        $lead = $this->teammatesLead($this->userWithRole('crm_bd'));
        // An event linked before this fix, owned by the BD but pointing at a teammate's lead.
        $event = PlannerEvent::factory()->create([
            'event_type' => 'call', 'status' => 'scheduled',
            'lead_id' => $lead->id, 'assigned_to' => $bd->id, 'created_by' => $bd->id,
        ]);
        Sanctum::actingAs($bd);

        $response = $this->postJson(self::EVENTS."/{$event->id}/calls")->assertForbidden();

        $this->assertLeadNotLeaked($response);
        $this->assertSame(0, Call::query()->where('planner_event_id', $event->id)->count());
        $this->assertSame('scheduled', $event->fresh()->status);
    }

    // ── The n8n automation account: owner-matched only ───────────────────────────────────────

    private function automationAccount(bool $withAutomationAbility = true): User
    {
        $account = User::factory()->create(['status' => 'active', 'name' => 'n8n Service Account']);
        $account->givePermissionTo(['crm.calendar.view', 'crm.calendar.create']);
        if ($withAutomationAbility) {
            $account->givePermissionTo('crm.outreach.automation.send');
        }

        return $account;
    }

    /** Workflow 01 posts {lead_id, assigned_to} from `proposal.sent`, where assigned_to is the lead's owner. */
    public function test_the_automation_account_links_a_lead_for_its_current_owner(): void
    {
        $owner = $this->userWithRole('crm_bd');
        $lead = Lead::factory()->assignedTo($owner)->create();
        Sanctum::actingAs($this->automationAccount());

        $this->postJson(self::EVENTS, [
            'title' => 'Proposal follow-up', 'event_type' => 'follow-up', 'source' => 'outreach',
            'lead_id' => $lead->id, 'assigned_to' => $owner->id, 'starts_at' => now()->addDays(3)->toIso8601String(),
        ])->assertCreated()->assertJsonPath('data.leadId', (string) $lead->id);

        // Workflow 02's email task, from `lead.qualified`.
        $this->postJson(self::EVENTS, [
            'title' => 'Introduction email', 'event_type' => 'email', 'source' => 'outreach',
            'lead_id' => $lead->id, 'assigned_to' => $owner->id, 'starts_at' => now()->toIso8601String(),
        ])->assertCreated();
    }

    public function test_the_automation_account_cannot_link_a_lead_for_anyone_but_its_owner(): void
    {
        $owner = $this->userWithRole('crm_bd');
        $someoneElse = $this->userWithRole('crm_bd');
        $ownedLead = Lead::factory()->assignedTo($owner)->create(['cold_calling' => 'not-called']); // pinned — see teammatesLead()
        $ownerlessLead = Lead::factory()->create(['assigned_to' => null, 'source' => null]);
        $account = $this->automationAccount();
        Sanctum::actingAs($account);

        $this->postJson(self::EVENTS, $this->meeting(['lead_id' => $ownedLead->id, 'assigned_to' => $someoneElse->id]))
            ->assertUnprocessable()->assertJsonPath('errors.lead_id.0', LinkableLead::MESSAGE);
        $this->postJson(self::EVENTS, $this->meeting(['lead_id' => $ownerlessLead->id, 'assigned_to' => $owner->id]))
            ->assertUnprocessable()->assertJsonPath('errors.lead_id.0', LinkableLead::MESSAGE);

        $this->assertSame(0, PlannerEvent::query()->count());
    }

    public function test_owner_matching_requires_the_automation_ability(): void
    {
        $owner = $this->userWithRole('crm_bd');
        $lead = Lead::factory()->assignedTo($owner)->create();
        Sanctum::actingAs($this->automationAccount(withAutomationAbility: false));

        $this->postJson(self::EVENTS, $this->meeting(['lead_id' => $lead->id, 'assigned_to' => $owner->id]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.lead_id.0', LinkableLead::MESSAGE);
    }
}
