<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Enums\CallOutcome;
use App\Modules\Crm\Models\Call;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Calling Workspace HTTP surface. These tests exercise the endpoints end-to-end (routing →
 * middleware → FormRequest → controller → CallSessionService → CallResource), pinning the response
 * shapes, status codes, request validation, and that domain-rule breaches surface as 422. Authorization
 * is covered separately in {@see CallAuthorizationTest}.
 */
class CallApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function bd(): User
    {
        $user = User::factory()->create();
        $user->assignRole('crm_bd');

        return $user;
    }

    private function ownedLead(User $bd): Lead
    {
        return Lead::factory()->create(['assigned_to' => $bd->id, 'created_by' => $bd->id]);
    }

    public function test_bd_starts_an_ad_hoc_call_from_the_lead(): void
    {
        $bd = $this->bd();
        $lead = $this->ownedLead($bd);
        Sanctum::actingAs($bd);

        $this->postJson("/api/v1/crm/leads/{$lead->id}/calls")
            ->assertCreated()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.direction', 'outbound')
            ->assertJsonPath('data.leadId', (string) $lead->id)
            ->assertJsonPath('data.agent.id', (string) $bd->id);

        // Planner-first: a `call` event now owns the call.
        $this->assertDatabaseHas('crm_planner_events', ['lead_id' => $lead->id, 'event_type' => 'call']);
        $this->assertSame(1, Call::where('lead_id', $lead->id)->count());
    }

    public function test_bd_starts_a_call_from_a_planner_event(): void
    {
        $bd = $this->bd();
        $lead = $this->ownedLead($bd);
        $event = PlannerEvent::factory()->create([
            'event_type' => 'call', 'status' => 'scheduled',
            'lead_id' => $lead->id, 'assigned_to' => $bd->id, 'created_by' => $bd->id,
        ]);
        Sanctum::actingAs($bd);

        $this->postJson("/api/v1/crm/planner-events/{$event->id}/calls")
            ->assertCreated()
            ->assertJsonPath('data.plannerEventId', (string) $event->id)
            ->assertJsonPath('data.status', 'in_progress');
    }

    public function test_complete_endpoint_dispositions_the_call(): void
    {
        $bd = $this->bd();
        $lead = $this->ownedLead($bd);
        $call = Call::factory()->forLead($lead)->byAgent($bd)->create();
        Sanctum::actingAs($bd);

        $this->patchJson("/api/v1/crm/calls/{$call->id}/complete", [
            'outcome' => 'connected',
            'notes' => 'Booked a demo',
        ])
            ->assertOk()
            ->assertJsonPath('data.outcome', 'connected')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.notes', 'Booked a demo');

        $this->assertSame('completed', $call->plannerEvent->fresh()->status);
    }

    public function test_discard_endpoint_cancels_the_call(): void
    {
        $bd = $this->bd();
        $lead = $this->ownedLead($bd);
        $call = Call::factory()->forLead($lead)->byAgent($bd)->create();
        Sanctum::actingAs($bd);

        $this->patchJson("/api/v1/crm/calls/{$call->id}/discard")
            ->assertOk()
            ->assertJsonPath('data.outcome', 'cancelled')
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_complete_rejects_the_cancelled_outcome(): void
    {
        $bd = $this->bd();
        $call = Call::factory()->forLead($this->ownedLead($bd))->byAgent($bd)->create();
        Sanctum::actingAs($bd);

        // `cancelled` is the Discard action, never a completion outcome.
        $this->patchJson("/api/v1/crm/calls/{$call->id}/complete", ['outcome' => 'cancelled'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('outcome');
    }

    public function test_complete_requires_a_valid_outcome(): void
    {
        $bd = $this->bd();
        $call = Call::factory()->forLead($this->ownedLead($bd))->byAgent($bd)->create();
        Sanctum::actingAs($bd);

        $this->patchJson("/api/v1/crm/calls/{$call->id}/complete", ['outcome' => 'banana'])
            ->assertStatus(422)->assertJsonValidationErrors('outcome');

        $this->patchJson("/api/v1/crm/calls/{$call->id}/complete", [])
            ->assertStatus(422)->assertJsonValidationErrors('outcome');
    }

    public function test_call_history_lists_a_leads_calls_newest_first(): void
    {
        $bd = $this->bd();
        $lead = $this->ownedLead($bd);

        // Three concluded attempts at distinct times so "newest first" is deterministic.
        $ids = [];
        foreach (['2026-07-20 09:00:00', '2026-07-21 09:00:00', '2026-07-22 09:00:00'] as $at) {
            $ids[] = Call::factory()->forLead($lead)->byAgent($bd)
                ->completed(CallOutcome::NO_ANSWER)->create(['created_at' => $at])->id;
        }
        Sanctum::actingAs($bd);

        $returned = $this->getJson("/api/v1/crm/leads/{$lead->id}/calls")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->json('data.*.id');

        $this->assertSame(array_map('strval', array_reverse($ids)), $returned);
    }

    public function test_call_history_matches_the_call_resource_shape(): void
    {
        $bd = $this->bd();
        $lead = $this->ownedLead($bd);
        Call::factory()->forLead($lead)->byAgent($bd)->completed()->create();
        Sanctum::actingAs($bd);

        $this->getJson("/api/v1/crm/leads/{$lead->id}/calls")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [[
                    'id', 'plannerEventId', 'leadId', 'direction', 'provider', 'status', 'outcome',
                    'startedAt', 'endedAt', 'durationSeconds', 'notes',
                    'agentId', 'agent' => ['id', 'name'],
                    'createdAt', 'updatedAt',
                ]],
            ]);
    }

    public function test_starting_a_second_active_call_on_an_event_returns_422(): void
    {
        $bd = $this->bd();
        $lead = $this->ownedLead($bd);
        $event = PlannerEvent::factory()->create([
            'event_type' => 'call', 'status' => 'scheduled',
            'lead_id' => $lead->id, 'assigned_to' => $bd->id, 'created_by' => $bd->id,
        ]);
        Sanctum::actingAs($bd);

        $this->postJson("/api/v1/crm/planner-events/{$event->id}/calls")->assertCreated();
        $this->postJson("/api/v1/crm/planner-events/{$event->id}/calls")
            ->assertStatus(422)
            ->assertJsonValidationErrors('call');
    }
}
