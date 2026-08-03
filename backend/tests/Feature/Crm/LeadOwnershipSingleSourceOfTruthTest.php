<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Events\LeadAssigned;
use App\Modules\Crm\Models\Lead;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Ownership of a lead changes through exactly ONE endpoint: PATCH /leads/{lead}/assign.
 *
 * The generic PUT /leads/{lead} used to accept `assigned_to` as an ordinary editable column, which
 * meant ownership could change down a path that enforces none of the guarantees the assign endpoint
 * exists to provide:
 *
 *   - `crm.leads.assign` (Admin/Super Admin only) — the generic update needs only `crm.leads.edit`,
 *     which a BD holds for their own leads, so a BD could hand their lead to anyone.
 *   - MustBeBusinessDeveloper — the generic update validated `exists:users,id` only, so a lead could
 *     be parked on a user with no BD role and drop out of every BD's ownership-scoped list.
 *   - The `lead.assigned` activity record and the LeadAssigned broadcast — both skipped, so the
 *     audit trail and every subscribed client silently missed the handover.
 *
 * `assigned_to` is therefore omitted from UpdateLeadRequest::rules() entirely (the same technique
 * IngestDiscoveredLeadsRequest uses): the controller applies `$request->validated()`, so a field
 * with no rule can never reach the model. These tests pin that down — the generic endpoint must keep
 * ignoring `assigned_to`, and the assign endpoint must remain the path that works.
 */
class LeadOwnershipSingleSourceOfTruthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function businessDeveloper(): User
    {
        $bd = User::factory()->create(['status' => 'active']);
        $bd->assignRole('crm_bd');

        return $bd;
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('crm_admin');

        return $admin;
    }

    /**
     * The headline rule. An Admin — who *does* hold `crm.leads.assign` and could legitimately
     * reassign via the proper endpoint — still cannot do it through the generic update. The field is
     * dropped rather than rejected, so the request succeeds and every other edit in it lands.
     */
    public function test_generic_update_ignores_assigned_to_and_leaves_ownership_untouched(): void
    {
        $owner = $this->businessDeveloper();
        $otherBd = $this->businessDeveloper();
        $lead = Lead::factory()->assignedTo($owner)->create(['business_name' => 'Ownership Co']);

        Sanctum::actingAs($this->admin());

        $this->putJson("/api/v1/crm/leads/{$lead->id}", [
            'business_name' => 'Ownership Co Renamed',
            'assigned_to' => $otherBd->id,
        ])->assertOk();

        $this->assertDatabaseHas('crm_leads', [
            'id' => $lead->id,
            // The legitimate edit in the same payload still applied...
            'business_name' => 'Ownership Co Renamed',
            // ...while ownership stayed exactly where it was.
            'assigned_to' => $owner->id,
        ]);
    }

    /**
     * The privilege escalation this closes: a BD may edit their own lead, so before the field was
     * removed they could reassign it — giving themselves an ownership-transfer power the route
     * guard on /assign deliberately withholds from them.
     */
    public function test_business_developer_cannot_reassign_their_own_lead_through_the_generic_update(): void
    {
        $owner = $this->businessDeveloper();
        $otherBd = $this->businessDeveloper();
        $lead = Lead::factory()->assignedTo($owner)->create();

        Sanctum::actingAs($owner);

        $this->putJson("/api/v1/crm/leads/{$lead->id}", [
            'assigned_to' => $otherBd->id,
        ])->assertOk();

        $this->assertDatabaseHas('crm_leads', [
            'id' => $lead->id,
            'assigned_to' => $owner->id,
        ]);
    }

    /**
     * A lead can never be parked on a non-BD. The generic update no longer offers the field at all,
     * and the assign endpoint refuses it via MustBeBusinessDeveloper — so neither door is open.
     */
    public function test_neither_endpoint_can_park_a_lead_on_a_user_without_the_bd_role(): void
    {
        $owner = $this->businessDeveloper();
        $nonBd = User::factory()->create(['status' => 'active']);
        $lead = Lead::factory()->assignedTo($owner)->create();

        Sanctum::actingAs($this->admin());

        $this->putJson("/api/v1/crm/leads/{$lead->id}", ['assigned_to' => $nonBd->id])
            ->assertOk();

        $this->patchJson("/api/v1/crm/leads/{$lead->id}/assign", ['assigned_to' => $nonBd->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('assigned_to');

        $this->assertDatabaseHas('crm_leads', [
            'id' => $lead->id,
            'assigned_to' => $owner->id,
        ]);
    }

    /**
     * Positive control — the single source of truth actually works. Without this, all three tests
     * above would still pass if ownership transfer were broken everywhere.
     */
    public function test_assign_endpoint_transfers_ownership_and_broadcasts(): void
    {
        // Narrow fake: the LeadObserver and the assign activity record still run.
        Event::fake([LeadAssigned::class]);

        $owner = $this->businessDeveloper();
        $newOwner = $this->businessDeveloper();
        $lead = Lead::factory()->assignedTo($owner)->create();

        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/v1/crm/leads/{$lead->id}/assign", [
            'assigned_to' => $newOwner->id,
        ])->assertOk();

        $this->assertDatabaseHas('crm_leads', [
            'id' => $lead->id,
            'assigned_to' => $newOwner->id,
        ]);

        Event::assertDispatched(
            LeadAssigned::class,
            fn (LeadAssigned $event): bool => $event->leadId === (string) $lead->id
                && $event->assignedToId === (string) $newOwner->id
        );
    }

    /** A BD holds no `crm.leads.assign`, so the proper endpoint is closed to them too. */
    public function test_business_developer_cannot_use_the_assign_endpoint(): void
    {
        $owner = $this->businessDeveloper();
        $otherBd = $this->businessDeveloper();
        $lead = Lead::factory()->assignedTo($owner)->create();

        Sanctum::actingAs($owner);

        $this->patchJson("/api/v1/crm/leads/{$lead->id}/assign", [
            'assigned_to' => $otherBd->id,
        ])->assertForbidden();

        $this->assertDatabaseHas('crm_leads', [
            'id' => $lead->id,
            'assigned_to' => $owner->id,
        ]);
    }
}
