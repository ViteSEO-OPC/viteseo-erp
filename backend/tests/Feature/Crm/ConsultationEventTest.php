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
 * PR-2 of the Consultation Workflow ([[PDR-002]]): `consultation` as a first-class Planner event type.
 *
 * Pins the two contracts this PR introduces — the type is accepted and directly authorable (unlike
 * `follow-up`), and a consultation must always be linked to a lead, because the Successful Consultations
 * KPI counts DISTINCT leads and an unlinked consultation could never be counted.
 *
 * The lead requirement is validated against the RESULTING state rather than with an implicit required
 * rule, so partial patches that touch neither field keep working; `test_board_status_drag_*` is the
 * regression guarding that.
 */
class ConsultationEventTest extends TestCase
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

    private function ownedLead(User $bd): Lead
    {
        return Lead::factory()->create(['assigned_to' => $bd->id, 'created_by' => $bd->id]);
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Consultation — Acme Corp',
            'event_type' => 'consultation',
            'status' => 'scheduled',
            'starts_at' => now()->addDay()->toIso8601String(),
        ], $overrides);
    }

    public function test_bd_creates_a_consultation_linked_to_a_lead(): void
    {
        $bd = $this->bd();
        $lead = $this->ownedLead($bd);
        Sanctum::actingAs($bd);

        $this->postJson('/api/v1/crm/planner-events', $this->payload(['lead_id' => $lead->id]))
            ->assertCreated()
            ->assertJsonPath('data.eventType', 'consultation');

        $this->assertDatabaseHas('crm_planner_events', [
            'event_type' => 'consultation',
            'lead_id' => $lead->id,
            'status' => 'scheduled',
        ]);
    }

    public function test_a_consultation_requires_a_lead(): void
    {
        Sanctum::actingAs($this->bd());

        $this->postJson('/api/v1/crm/planner-events', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lead_id');
    }

    /** The lead requirement is scoped to consultations — every other type stays lead-optional. */
    public function test_other_event_types_still_allow_a_missing_lead(): void
    {
        Sanctum::actingAs($this->bd());

        foreach (['email', 'call', 'meeting', 'task'] as $type) {
            $this->postJson('/api/v1/crm/planner-events', $this->payload([
                'title' => "A {$type}",
                'event_type' => $type,
            ]))->assertCreated();
        }
    }

    /**
     * REGRESSION — the Board's click-to-move patches `status` alone. An implicit `required` rule on
     * `lead_id` would reject that for consultations, silently breaking drag-and-drop for the new type.
     */
    public function test_board_status_drag_still_works_on_a_consultation(): void
    {
        $bd = $this->bd();
        $lead = $this->ownedLead($bd);
        $event = PlannerEvent::factory()->assignedTo($bd)->create([
            'event_type' => 'consultation',
            'status' => 'scheduled',
            'lead_id' => $lead->id,
            'created_by' => $bd->id,
        ]);
        Sanctum::actingAs($bd);

        $this->patchJson("/api/v1/crm/planner-events/{$event->id}", ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_a_consultation_cannot_have_its_lead_removed(): void
    {
        $bd = $this->bd();
        $lead = $this->ownedLead($bd);
        $event = PlannerEvent::factory()->assignedTo($bd)->create([
            'event_type' => 'consultation',
            'status' => 'scheduled',
            'lead_id' => $lead->id,
            'created_by' => $bd->id,
        ]);
        Sanctum::actingAs($bd);

        $this->patchJson("/api/v1/crm/planner-events/{$event->id}", ['lead_id' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lead_id');
    }

    /** The other route to a lead-less consultation: converting an unlinked event into one. */
    public function test_a_leadless_event_cannot_be_converted_into_a_consultation(): void
    {
        $bd = $this->bd();
        $event = PlannerEvent::factory()->assignedTo($bd)->create([
            'event_type' => 'task',
            'status' => 'scheduled',
            'lead_id' => null,
            'created_by' => $bd->id,
        ]);
        Sanctum::actingAs($bd);

        $this->patchJson("/api/v1/crm/planner-events/{$event->id}", ['event_type' => 'consultation'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lead_id');

        // …but converting it *with* a lead in the same patch is allowed.
        $lead = $this->ownedLead($bd);
        $this->patchJson("/api/v1/crm/planner-events/{$event->id}", [
            'event_type' => 'consultation',
            'lead_id' => $lead->id,
        ])->assertOk()->assertJsonPath('data.eventType', 'consultation');
    }

    /**
     * 🔒 The frozen outcome vocabulary (PDR-002 §4b) lives on its own axis. `status` is untouched, so a
     * cancelled consultation is a COMPLETED event carrying a `cancelled` outcome — exactly how a
     * cancelled call is already recorded on crm_calls.
     */
    public function test_a_consultation_records_an_outcome_without_touching_its_lifecycle(): void
    {
        $bd = $this->bd();
        $lead = $this->ownedLead($bd);
        $event = PlannerEvent::factory()->assignedTo($bd)->create([
            'event_type' => 'consultation',
            'status' => 'scheduled',
            'lead_id' => $lead->id,
            'created_by' => $bd->id,
        ]);
        Sanctum::actingAs($bd);

        $this->patchJson("/api/v1/crm/planner-events/{$event->id}", [
            'status' => 'completed',
            'outcome' => 'cancelled',
        ])->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.outcome', 'cancelled');
    }

    public function test_every_approved_outcome_is_accepted_and_anything_else_is_rejected(): void
    {
        $bd = $this->bd();
        $lead = $this->ownedLead($bd);
        Sanctum::actingAs($bd);

        foreach (['successful', 'not_interested', 'no_show', 'cancelled'] as $outcome) {
            $this->postJson('/api/v1/crm/planner-events', $this->payload([
                'lead_id' => $lead->id,
                'status' => 'completed',
                'outcome' => $outcome,
            ]))->assertCreated()->assertJsonPath('data.outcome', $outcome);
        }

        // 'rescheduled' is a scheduling action, not a result — it must never be storable as one.
        foreach (['rescheduled', 'follow_up_required', 'confirmed'] as $rejected) {
            $this->postJson('/api/v1/crm/planner-events', $this->payload([
                'lead_id' => $lead->id,
                'outcome' => $rejected,
            ]))->assertUnprocessable()->assertJsonValidationErrors('outcome');
        }
    }

    /** Outcome is consultation-only vocabulary — it must not leak onto other event types. */
    public function test_other_event_types_cannot_record_an_outcome(): void
    {
        $bd = $this->bd();
        $lead = $this->ownedLead($bd);
        Sanctum::actingAs($bd);

        $this->postJson('/api/v1/crm/planner-events', $this->payload([
            'event_type' => 'meeting',
            'lead_id' => $lead->id,
            'outcome' => 'successful',
        ]))->assertUnprocessable()->assertJsonValidationErrors('outcome');
    }

    /** Converting a consultation away from its type must not strand its outcome. */
    public function test_an_outcome_cannot_be_stranded_by_changing_the_event_type(): void
    {
        $bd = $this->bd();
        $lead = $this->ownedLead($bd);
        $event = PlannerEvent::factory()->assignedTo($bd)->create([
            'event_type' => 'consultation',
            'status' => 'completed',
            'outcome' => 'successful',
            'lead_id' => $lead->id,
            'created_by' => $bd->id,
        ]);
        Sanctum::actingAs($bd);

        $this->patchJson("/api/v1/crm/planner-events/{$event->id}", ['event_type' => 'meeting'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('outcome');

        // Clearing the outcome in the same patch makes the conversion legitimate.
        $this->patchJson("/api/v1/crm/planner-events/{$event->id}", [
            'event_type' => 'meeting',
            'outcome' => null,
        ])->assertOk()->assertJsonPath('data.outcome', null);
    }

    /** An unknown type is still rejected — the enum was extended, not opened up. */
    public function test_an_unknown_event_type_is_still_rejected(): void
    {
        $bd = $this->bd();
        $lead = $this->ownedLead($bd);
        Sanctum::actingAs($bd);

        $this->postJson('/api/v1/crm/planner-events', $this->payload([
            'event_type' => 'workshop',
            'lead_id' => $lead->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors('event_type');
    }
}
