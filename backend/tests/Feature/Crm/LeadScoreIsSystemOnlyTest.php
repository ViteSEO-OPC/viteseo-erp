<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Scoring\LeadScorer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Lead Score is calculated by the system and never typed in (CEO audit crm-08).
 *
 * LeadScorer is the only writer of `leads.score` (BD-PERF-COMP-SPEC R-4). The generic create and
 * update endpoints used to accept `score` as an ordinary field, and because `score` is not a scoring
 * input the observer did not recompute after a score-only edit — so a hand-set value stuck until the
 * next recompute and fed BD Ranking in the meantime.
 *
 * `score` is therefore omitted from StoreLeadRequest and UpdateLeadRequest (the technique
 * LeadOwnershipSingleSourceOfTruthTest pins for `assigned_to`): the controller applies
 * `$request->validated()`, so the field is dropped rather than rejected and every other edit in the
 * same request still lands.
 */
class LeadScoreIsSystemOnlyTest extends TestCase
{
    use RefreshDatabase;

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

    /** What the system says the lead's score is right now. */
    private function systemScore(Lead $lead): int
    {
        return app(LeadScorer::class)->calculate($lead->fresh())->score;
    }

    /** A value that is valid under the old rule and different from what the lead already holds. */
    private function handSetScoreFor(Lead $lead): int
    {
        return $lead->fresh()->score === 100 ? 0 : 100;
    }

    public function test_a_business_developer_cannot_set_their_own_leads_score(): void
    {
        $bd = $this->userWithRole('crm_bd');
        $lead = Lead::factory()->assignedTo($bd)->create();
        $before = $lead->fresh()->score;
        $handSet = $this->handSetScoreFor($lead);

        Sanctum::actingAs($bd);

        $this->patchJson("/api/v1/crm/leads/{$lead->id}", ['score' => $handSet])->assertOk();

        $this->assertSame($before, $lead->fresh()->score);
        $this->assertNotSame($handSet, $lead->fresh()->score);
        $this->assertSame($this->systemScore($lead), $lead->fresh()->score);
    }

    public function test_the_score_is_dropped_while_the_rest_of_the_edit_still_lands(): void
    {
        $bd = $this->userWithRole('crm_bd');
        $lead = Lead::factory()->assignedTo($bd)->create(['priority' => 'low']);
        $before = $lead->fresh()->score;

        Sanctum::actingAs($bd);

        $this->patchJson("/api/v1/crm/leads/{$lead->id}", [
            'priority' => 'high',
            'score' => $this->handSetScoreFor($lead),
        ])->assertOk();

        $this->assertSame('high', $lead->fresh()->priority);
        $this->assertSame($before, $lead->fresh()->score);
    }

    public function test_an_admin_cannot_set_a_leads_score_either(): void
    {
        $lead = Lead::factory()->assignedTo($this->userWithRole('crm_bd'))->create();
        $before = $lead->fresh()->score;

        Sanctum::actingAs($this->userWithRole('crm_admin'));

        $this->patchJson("/api/v1/crm/leads/{$lead->id}", ['score' => $this->handSetScoreFor($lead)])->assertOk();

        $this->assertSame($before, $lead->fresh()->score);
    }

    /** Dropped, not rejected: a stale client still sending a score keeps working. */
    public function test_an_invalid_score_is_ignored_rather_than_rejected(): void
    {
        $bd = $this->userWithRole('crm_bd');
        $lead = Lead::factory()->assignedTo($bd)->create();
        $before = $lead->fresh()->score;

        Sanctum::actingAs($bd);

        $this->patchJson("/api/v1/crm/leads/{$lead->id}", ['score' => 500])->assertOk();
        $this->patchJson("/api/v1/crm/leads/{$lead->id}", ['score' => 'high'])->assertOk();

        $this->assertSame($before, $lead->fresh()->score);
    }

    public function test_a_score_sent_on_create_is_replaced_by_the_system_score(): void
    {
        Sanctum::actingAs($this->userWithRole('crm_bd'));

        $id = $this->postJson('/api/v1/crm/leads', [
            'business_name' => 'Score Fixture Co',
            'contact_name' => 'Maria Fixture',
            'score' => 100,
        ])->assertCreated()->json('data.id');

        $lead = Lead::query()->findOrFail($id);
        $this->assertSame($this->systemScore($lead), $lead->score);
        $this->assertNotSame(100, $lead->score);
    }

    /** The system is still the writer: a real scoring input moves the score as before. */
    public function test_changing_a_scoring_input_still_recomputes_the_score(): void
    {
        $bd = $this->userWithRole('crm_bd');
        $lead = Lead::factory()->assignedTo($bd)->create(['status' => 'new']);

        Sanctum::actingAs($bd);

        $this->patchJson("/api/v1/crm/leads/{$lead->id}", ['status' => 'interested'])->assertOk();

        $this->assertSame('interested', $lead->fresh()->status);
        $this->assertSame($this->systemScore($lead), $lead->fresh()->score);
    }
}
