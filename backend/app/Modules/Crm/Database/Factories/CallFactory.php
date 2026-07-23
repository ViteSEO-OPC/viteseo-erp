<?php

namespace App\Modules\Crm\Database\Factories;

use App\Models\User;
use App\Modules\Crm\Enums\CallDirection;
use App\Modules\Crm\Enums\CallOutcome;
use App\Modules\Crm\Enums\CallStatus;
use App\Modules\Crm\Models\Call;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Call>
 *
 * Builds Call detail records for tests. Factories create through Model::unguarded, so the ownership
 * FKs (planner_event_id / lead_id / user_id) — deliberately NOT mass-assignable on the model — are set
 * here without weakening that guard for real client input. The default is a fresh in-progress outbound
 * call whose owning event is a `call` PlannerEvent; {@see forLead()} wires a coherent lead ⇄ event ⇄ call
 * graph, and {@see completed()} concludes it with a disposition.
 */
class CallFactory extends Factory
{
    /**
     * Set explicitly: the factory lives under App\Modules\Crm\, so Laravel's
     * convention-based resolver cannot infer the model and guesses App\Call.
     */
    protected $model = Call::class;

    public function definition(): array
    {
        return [
            'planner_event_id' => PlannerEvent::factory()->state(['event_type' => 'call']),
            'lead_id' => Lead::factory(),
            'user_id' => User::factory(),
            'direction' => CallDirection::OUTBOUND->value,
            'provider' => 'manual',
            'status' => CallStatus::IN_PROGRESS->value,
            'outcome' => null,
            'started_at' => now(),
            'ended_at' => null,
            'duration_seconds' => null,
            'notes' => null,
        ];
    }

    /** A concluded call with a disposition (default `connected`), timed and ended. */
    public function completed(CallOutcome $outcome = CallOutcome::CONNECTED): static
    {
        return $this->state(fn (): array => [
            'status' => CallStatus::COMPLETED->value,
            'outcome' => $outcome->value,
            'ended_at' => now(),
            'duration_seconds' => fake()->numberBetween(30, 900),
        ]);
    }

    /**
     * Attach the call to a lead and a matching `call` PlannerEvent for that same lead — a coherent graph
     * where the event, the call, and the lead all agree (what {@see Lead::calls()} / Call History expect).
     */
    public function forLead(Lead $lead): static
    {
        return $this->state(fn (): array => [
            'lead_id' => $lead->id,
            'planner_event_id' => PlannerEvent::factory()->state([
                'event_type' => 'call',
                'lead_id' => $lead->id,
                'assigned_to' => $lead->assigned_to,
            ]),
        ]);
    }

    /** The BD who placed the call. */
    public function byAgent(User $agent): static
    {
        return $this->state(fn (): array => ['user_id' => $agent->id]);
    }
}
