<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A claimed prospect STAYS on the Nearby board, marked and locked.
 *
 * Two BDs see the same shared pool, so both can press "Accept into CRM" on one business. The claim
 * itself was already exactly-once (see LeadQualificationTest): a locked transaction means the second
 * request loses and nothing duplicates. What was missing was the BOARD telling the loser why — the
 * row simply vanished, which reads as data loss rather than as a colleague getting there first.
 *
 * So the Nearby query widened from `prospect()` to `discovered()`: every discovered business, claimed
 * or not. The widening is READ-ONLY and deliberately narrow in blast radius —
 *
 *  - `scopeProspect` is untouched, because Activity visibility still depends on it;
 *  - `source IS NOT NULL` still holds, so hand-created and imported leads never appear here;
 *  - ownership is still decided solely by the locked qualify transaction. `qualified` on the payload
 *    DESCRIBES a claim; it never makes one.
 *
 * These tests pin the two halves that could regress independently: that a claimed business is still
 * listed with the claimant's name, and that being listed does not make it claimable again.
 */
class NearbyClaimedVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const NEARBY = '/api/v1/crm/nearby-clients';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        // LeadFactory resolves `created_by` to an existing user and falls back to id 1, which would
        // violate the FK with no users present. This one owns nothing and affects no assertion.
        User::factory()->create(['status' => 'active']);
    }

    private function bd(string $name): User
    {
        $user = User::factory()->create(['status' => 'active', 'name' => $name]);
        $user->assignRole('crm_bd');

        return $user;
    }

    private function prospect(string $name): Lead
    {
        return Lead::factory()->create([
            'assigned_to' => null,
            'qualified_at' => null,
            'qualified_by' => null,
            'source' => 'nearby',
            'business_name' => $name,
        ]);
    }

    /** The Nearby payload keyed by business name. */
    private function board(): array
    {
        $rows = $this->getJson(self::NEARBY)->assertOk()->json('data');

        return collect($rows)->keyBy('business')->all();
    }

    // ── Visibility ──────────────────────────────────────────────────────────────────────────────

    public function test_a_claimed_prospect_remains_visible_on_the_nearby_board(): void
    {
        $winner = $this->bd('Rea Villanueva');
        Sanctum::actingAs($winner);

        $claimed = $this->prospect('Molave Dental Clinic');
        $open = $this->prospect('Casa Verde Bistro');
        $this->postJson("/api/v1/crm/leads/{$claimed->id}/qualify")->assertCreated();

        $board = $this->board();

        $this->assertArrayHasKey('Molave Dental Clinic', $board, 'the claimed business left the board');
        $this->assertArrayHasKey('Casa Verde Bistro', $board, 'the open prospect left the board');
        $this->assertTrue($board['Molave Dental Clinic']['qualified']);
        $this->assertFalse($board['Casa Verde Bistro']['qualified']);
    }

    public function test_the_board_names_the_bd_who_claimed_it(): void
    {
        $winner = $this->bd('Rea Villanueva');
        Sanctum::actingAs($winner);
        $lead = $this->prospect('Molave Dental Clinic');
        $this->postJson("/api/v1/crm/leads/{$lead->id}/qualify")->assertCreated();

        // A DIFFERENT BD reads the board — this is the person the name is for.
        Sanctum::actingAs($this->bd('Jhamire Aranda'));
        $row = $this->board()['Molave Dental Clinic'];

        $this->assertSame('Rea Villanueva', $row['qualifiedBy']['name']);
        $this->assertNotNull($row['qualifiedAt']);
    }

    /** Only the claimant's name is published — never the rest of the user record. */
    public function test_the_claimant_projection_exposes_only_id_and_name(): void
    {
        $winner = $this->bd('Rea Villanueva');
        Sanctum::actingAs($winner);
        $lead = $this->prospect('Molave Dental Clinic');
        $this->postJson("/api/v1/crm/leads/{$lead->id}/qualify")->assertCreated();

        $this->assertSame(['id', 'name'], array_keys($this->board()['Molave Dental Clinic']['qualifiedBy']));
    }

    /** An unclaimed prospect carries the fields too, so the UI never branches on their absence. */
    public function test_an_unclaimed_prospect_reports_null_claim_metadata(): void
    {
        Sanctum::actingAs($this->bd('Jhamire Aranda'));
        $this->prospect('Casa Verde Bistro');

        $row = $this->board()['Casa Verde Bistro'];

        $this->assertFalse($row['qualified']);
        $this->assertNull($row['qualifiedAt']);
        $this->assertNull($row['qualifiedBy']);
    }

    /** The widening must not drag manual/imported leads onto a discovery surface. */
    public function test_hand_created_leads_still_never_appear_on_the_board(): void
    {
        Sanctum::actingAs($this->bd('Jhamire Aranda'));
        Lead::factory()->create(['source' => null, 'business_name' => 'Manually Entered Co']);
        $this->prospect('Casa Verde Bistro');

        $this->assertArrayNotHasKey('Manually Entered Co', $this->board());
    }

    // ── Competing BDs ───────────────────────────────────────────────────────────────────────────

    /**
     * The whole scenario end to end: BD A wins, BD B sees WHO won, BD B is refused, and there is
     * still exactly one lead row for the business.
     */
    public function test_the_second_bd_sees_the_claim_is_refused_and_creates_no_duplicate(): void
    {
        $winner = $this->bd('Rea Villanueva');
        $loser = $this->bd('Jhamire Aranda');

        Sanctum::actingAs($winner);
        $lead = $this->prospect('Molave Dental Clinic');
        $this->postJson("/api/v1/crm/leads/{$lead->id}/qualify")->assertCreated();

        Sanctum::actingAs($loser);

        // 1. The loser's board still shows it, marked, with the winner's name — this is what disables
        //    the Accept button client-side.
        $row = $this->board()['Molave Dental Clinic'];
        $this->assertTrue($row['qualified'], 'the board did not mark it claimed, so Accept would render enabled');
        $this->assertSame('Rea Villanueva', $row['qualifiedBy']['name']);

        // 2. And the server refuses anyway, however the click arrives. Being listed is not permission.
        $this->postJson("/api/v1/crm/leads/{$lead->id}/qualify")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Already added to CRM by Rea Villanueva.');

        // 3. Ownership untouched and still exactly ONE row for the business.
        $fresh = $lead->fresh();
        $this->assertSame($winner->id, $fresh->qualified_by);
        $this->assertSame($winner->id, $fresh->assigned_to);
        $this->assertSame(1, Lead::query()->where('business_name', 'Molave Dental Clinic')->count());
    }

    /** Visibility is symmetric: every BD sees the same claimed row, not just the two who raced. */
    public function test_every_bd_sees_the_same_claimed_state(): void
    {
        $winner = $this->bd('Rea Villanueva');
        Sanctum::actingAs($winner);
        $lead = $this->prospect('Molave Dental Clinic');
        $this->postJson("/api/v1/crm/leads/{$lead->id}/qualify")->assertCreated();

        foreach (['Jhamire Aranda', 'Divine Lacsamana', 'Paolo Mercado'] as $name) {
            Sanctum::actingAs($this->bd($name));
            $row = $this->board()['Molave Dental Clinic'];
            $this->assertTrue($row['qualified'], "{$name} saw it as unclaimed");
            $this->assertSame('Rea Villanueva', $row['qualifiedBy']['name']);
        }
    }
}
