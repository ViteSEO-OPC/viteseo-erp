<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\Lead;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * OUTCOME STATE — the read-only Won/Lost projections on Lead Details, and the endpoint contract
 * behind Mark as Won.
 *
 * Won deliberately writes NOTHING to the lead: no status (the Lead Status enum has no `won`), no
 * `amount` (that column is the prospect-stage ESTIMATE, never overwritten by realized revenue), no
 * score. The Deal alone records the conversion. That design is correct but invisible — Lead Details
 * showed no trace of a won lead, which is why the action read as "the Won button does nothing".
 *
 * The fix is a projection, not a behaviour change: `show()` eager-loads `deals` and LeadResource
 * exposes `deal` (amount + convertedAt) so the header can render a WON badge. This test locks BOTH
 * halves — the projection appears, and the lead row still does not move. If someone later "fixes"
 * the invisibility by writing a status or copying the deal amount onto the lead, the assertions in
 * {@see test_won_does_not_mutate_the_lead_row} fail, which is the point.
 *
 * Cases 3–5 are also the endpoint's first contract coverage: 201, the amount fallback, and the 409
 * duplicate guard were previously untested ({@see WonLeadClosesChasingWorkTest} covers only the
 * follow-up closure rule).
 */
class LeadWonStateTest extends TestCase
{
    use RefreshDatabase;

    private User $bd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->bd = User::factory()->create(['status' => 'active']);
        $this->bd->assignRole('crm_admin');
    }

    /**
     * `cold_calling` is pinned off `scheduled` because LeadObserver auto-creates a cold-call
     * follow-up on that value — irrelevant work for these assertions. `amount` is the ₱120,000
     * estimate from the approved target screenshot; `status` stays `new` to prove Won leaves it there.
     */
    private function lead(array $overrides = []): Lead
    {
        return Lead::factory()->create(array_merge([
            'assigned_to' => $this->bd->id,
            'created_by' => $this->bd->id,
            'cold_calling' => 'not-called',
            'status' => 'new',
            'amount' => 120000,
        ], $overrides));
    }

    private function markWon(Lead $lead, array $payload = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->bd)->postJson("/api/v1/crm/leads/{$lead->id}/won", $payload);
    }

    private function show(Lead $lead): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->bd)->getJson("/api/v1/crm/leads/{$lead->id}");
    }

    // ── 1. The projection ───────────────────────────────────────────────────────────────────────

    public function test_show_projects_the_deal_for_a_won_lead(): void
    {
        $lead = $this->lead();
        $deal = Deal::factory()->create([
            'lead_id' => $lead->id,
            'amount' => 120000,
            'converted_at' => now(),
        ]);

        $response = $this->show($lead)
            ->assertOk()
            ->assertJsonPath('data.deal.id', (string) $deal->id)
            ->assertJsonPath('data.deal.convertedAt', $deal->converted_at->toIso8601String());

        // assertEquals, not assertJsonPath: json_encode drops the zero fraction, so a whole-peso
        // amount decodes as int. The value is what matters here, not its JSON numeric type.
        $this->assertEquals(120000, $response->json('data.deal.amount'));
    }

    public function test_show_returns_a_null_deal_for_a_lead_that_is_not_won(): void
    {
        $this->show($this->lead())
            ->assertOk()
            ->assertJsonPath('data.deal', null);
    }

    /**
     * One Deal per lead is the API rule (the duplicate guard), so the projection is unambiguous.
     * Legacy/seeded rows could still carry more than one; the resource resolves to the LATEST, and
     * this pins that so a stale first-created deal can never be the one shown on the header.
     */
    public function test_the_projection_resolves_to_the_latest_deal(): void
    {
        $lead = $this->lead();
        Deal::factory()->create(['lead_id' => $lead->id, 'amount' => 15000, 'converted_at' => now()->subYear()]);
        $latest = Deal::factory()->create(['lead_id' => $lead->id, 'amount' => 99000, 'converted_at' => now()]);

        $response = $this->show($lead)
            ->assertOk()
            ->assertJsonPath('data.deal.id', (string) $latest->id);

        $this->assertEquals(99000, $response->json('data.deal.amount'));
    }

    // ── 2. The lead row must not move ───────────────────────────────────────────────────────────

    /**
     * The regression lock on the whole slice. Won records revenue on the Deal; the lead keeps its
     * status, its prospect estimate, and its score. Both the DB row and what the API reports back
     * are asserted, because the badge must never be produced by mutating the lead.
     */
    public function test_won_does_not_mutate_the_lead_row(): void
    {
        $lead = $this->lead();
        // Baseline from the DB, not from the in-memory factory instance: LeadObserver::created scores
        // the new lead through LeadScorer::refresh (updateQuietly), so the persisted row is the only
        // trustworthy "before". Comparing against the stale attribute would report a change that
        // happened at creation as if Won had caused it.
        $before = DB::table('crm_leads')->where('id', $lead->id)->first();

        $this->markWon($lead, ['amount' => 250000])->assertCreated();

        $after = DB::table('crm_leads')->where('id', $lead->id)->first();
        $this->assertSame($before->status, $after->status, 'Won must not touch the frozen Lead Status enum');
        $this->assertEquals($before->amount, $after->amount, 'leads.amount is the estimate, not realized revenue');
        $this->assertSame($before->score, $after->score, 'Won is not a scoring input');
        $this->assertEquals($before->updated_at, $after->updated_at, 'no lead column was written at all');

        $response = $this->show($lead)
            ->assertJsonPath('data.updateStatus', $before->status)
            ->assertJsonPath('data.score', $before->score);

        $this->assertEquals(120000, $response->json('data.amount'), 'the estimate is reported unchanged');
        $this->assertEquals(250000, $response->json('data.deal.amount'), 'realized revenue lives on the Deal');
    }

    // ── 3–5. Endpoint contract ──────────────────────────────────────────────────────────────────

    public function test_won_creates_exactly_one_deal_with_a_conversion_timestamp(): void
    {
        $lead = $this->lead();

        $this->markWon($lead, ['amount' => 250000])->assertCreated();

        $this->assertSame(1, $lead->deals()->count());
        $deal = $lead->deals()->sole();
        $this->assertEquals(250000, $deal->amount);
        $this->assertNotNull($deal->converted_at, 'converted_at is the conversion timestamp the header shows');
    }

    /** Amount is optional: an omitted body falls back to the lead's prospect estimate. */
    public function test_an_omitted_amount_falls_back_to_the_lead_estimate(): void
    {
        $lead = $this->lead();

        $this->markWon($lead)->assertCreated();

        $this->assertEquals(120000, $lead->deals()->sole()->amount);
    }

    /** ...and a lead with no estimate still wins — it simply books a zero-value deal. */
    public function test_a_lead_without_an_estimate_can_still_be_won(): void
    {
        $lead = $this->lead(['amount' => null]);

        $this->markWon($lead)->assertCreated();

        $this->assertEquals(0, $lead->deals()->sole()->amount);
    }

    /**
     * The duplicate guard. The Won button stays enabled on an already-won lead (PO decision), so this
     * 409 is reachable from the UI and its error toast is the user-facing feedback — which makes the
     * contract worth pinning rather than assuming.
     */
    public function test_a_second_won_is_rejected_and_creates_no_second_deal(): void
    {
        $lead = $this->lead();
        $this->markWon($lead)->assertCreated();

        $this->markWon($lead)->assertStatus(409);

        $this->assertSame(1, $lead->deals()->count());
    }

    public function test_a_negative_amount_is_rejected(): void
    {
        $lead = $this->lead();

        $this->markWon($lead, ['amount' => -1])->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->assertSame(0, $lead->deals()->count());
    }

    // ── Lost projection ─────────────────────────────────────────────────────────────────────────

    /**
     * Lost persists NOTHING but an Activity, so `lost` is derived. It is resolved by an EXISTS
     * subquery rather than by scanning a loaded feed — which also means it stays correct for a lead
     * carrying hundreds of newer activities, where the paginated activity endpoint would not even
     * return the `lead.lost` row.
     */
    public function test_show_reports_a_lost_lead(): void
    {
        $lead = $this->lead();
        $this->actingAs($this->bd)->postJson("/api/v1/crm/leads/{$lead->id}/lost", [])->assertCreated();

        $this->show($lead)
            ->assertOk()
            ->assertJsonPath('data.lost', true)
            ->assertJsonPath('data.deal', null);
    }

    public function test_show_reports_an_active_lead_as_not_lost(): void
    {
        $this->show($this->lead())
            ->assertOk()
            ->assertJsonPath('data.lost', false);
    }

    /** Buried under newer activity, a lost lead is still lost — the guard against a feed-scan fix. */
    public function test_lost_survives_a_busy_activity_feed(): void
    {
        $lead = $this->lead();
        $this->actingAs($this->bd)->postJson("/api/v1/crm/leads/{$lead->id}/lost", [])->assertCreated();

        for ($i = 0; $i < 20; $i++) {
            $lead->activities()->create([
                'causer_id' => $this->bd->id,
                'event' => 'updated',
                'description' => "later activity {$i}",
            ]);
        }

        $this->show($lead)->assertOk()->assertJsonPath('data.lost', true);
    }

    /**
     * Won and Lost are not mutually exclusive in the data (nothing stops a written-off lead being
     * signed later), so the API reports both truthfully and the UI decides precedence. Pinned so a
     * later "cleanup" does not start suppressing one server-side, where the ring cannot see it.
     */
    public function test_a_lead_can_report_both_outcomes(): void
    {
        $lead = $this->lead();
        $this->actingAs($this->bd)->postJson("/api/v1/crm/leads/{$lead->id}/lost", [])->assertCreated();
        $this->markWon($lead)->assertCreated();

        $response = $this->show($lead)->assertOk()->assertJsonPath('data.lost', true);

        $this->assertEquals(120000, $response->json('data.deal.amount'));
    }

    // ── The list payload is deliberately untouched ──────────────────────────────────────────────

    /**
     * `deal` is exposed behind `whenLoaded`, and only show() eager-loads it. The Leads list keeps its
     * exact previous shape — no key, no per-row deal query. Guards both the "don't modify the Leads
     * list" boundary and the N+1 the naive version of this change would have introduced.
     */
    public function test_the_leads_list_payload_carries_no_deal_key(): void
    {
        $lead = $this->lead();
        Deal::factory()->create(['lead_id' => $lead->id]);

        $response = $this->actingAs($this->bd)->getJson('/api/v1/crm/leads')->assertOk();

        $this->assertArrayNotHasKey('deal', $response->json('data.0'));
        $this->assertArrayNotHasKey('lost', $response->json('data.0'));
    }
}
