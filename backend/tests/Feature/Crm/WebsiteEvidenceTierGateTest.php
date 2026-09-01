<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadWebsiteCandidate;
use App\Modules\Crm\Services\Website\WebsiteEvidenceService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Exhaustion is relative to WHERE THERE WAS TO LOOK.
 *
 * The evidence chain already refused to conclude absence without a human, and refused to call a
 * search exhausted while a candidate sat unchecked. Neither guard could see the gap this one closes:
 * a run that only read OSM tags and Wikidata found nothing and said so, and "found nothing" was
 * recorded identically to "looked everywhere and found nothing".
 *
 * That difference is not academic. Tier 0 is a LOOKUP — measured, `brand:wikidata` covers 64% of
 * pharmacies and 0% of dentists, clinics and salons — so for an independent business it does not
 * fail to find a website, it never looks for one. A human shown that trail sees a complete search
 * and has no way to tell it was never filled in, which is the one remaining path by which an honest
 * confirmation still produces a wrong answer.
 *
 * These pin the guard from both sides: an incomplete search may not claim exhaustion, and a complete
 * one still may not conclude anything without a person.
 */
class WebsiteEvidenceTierGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        User::factory()->create(['status' => 'active']);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);

        return $user;
    }

    /** A discovered prospect with no URL — the population this feature exists for. */
    private function discoveredLead(?User $owner = null): Lead
    {
        return Lead::factory()->create([
            'business_name' => 'Vigan Dental Care',
            'contact_name' => 'Vigan Dental Care',
            'city' => 'Vigan',
            'email' => null,
            'website' => null,
            'website_status' => 'website-unknown',
            'source' => 'openstreetmap',
            'place_id' => 'node/'.fake()->unique()->numberBetween(1000, 99999),
            'qualified_at' => null,
            'assigned_to' => $owner?->id,
        ]);
    }

    private function service(): WebsiteEvidenceService
    {
        return app(WebsiteEvidenceService::class);
    }

    // --- the gate itself --------------------------------------------------------

    public function test_a_tier_0_only_search_may_not_call_itself_exhausted(): void
    {
        $lead = $this->discoveredLead();

        // Exactly what the pipeline did before Tier 1 existed: two lookups, nothing found.
        $this->service()->recordCandidates($lead, [], ['osm_tag', 'wikidata']);
        $this->service()->markExhausted($lead);

        $this->assertFalse($this->service()->searchFor($lead)->isExhausted());
    }

    public function test_a_search_covering_every_required_tier_is_exhausted(): void
    {
        $lead = $this->discoveredLead();

        $this->service()->recordCandidates($lead, [], WebsiteEvidenceService::REQUIRED_TIERS);
        $this->service()->markExhausted($lead);

        $this->assertTrue($this->service()->searchFor($lead)->isExhausted());
    }

    public function test_the_missing_tier_can_arrive_on_a_later_pass(): void
    {
        // tiers_run unions across passes, so a Tier 1 run that follows a Tier 0 run completes the
        // search rather than starting a new one — the scraper submits each tier as it finishes.
        $lead = $this->discoveredLead();

        $this->service()->recordCandidates($lead, [], ['osm_tag', 'wikidata']);
        $this->service()->markExhausted($lead);
        $this->assertFalse($this->service()->searchFor($lead)->isExhausted());

        $this->service()->recordCandidates($lead, [], [LeadWebsiteCandidate::SOURCE_PROBE]);
        $this->service()->markExhausted($lead);

        $this->assertTrue($this->service()->searchFor($lead)->isExhausted());
    }

    public function test_the_gate_is_silent_rather_than_an_error(): void
    {
        // The runner re-declares exhaustion on every pass; throwing would cost the whole batch its
        // evidence over a lead that is simply not finished yet.
        $lead = $this->discoveredLead();

        $this->service()->recordCandidates($lead, [], ['osm_tag']);
        $search = $this->service()->markExhausted($lead);

        $this->assertNull($search->exhausted_at);
        $this->assertTrue($search->exists);
    }

    // --- what the gate must NOT change ------------------------------------------

    public function test_absence_cannot_be_confirmed_while_a_tier_has_not_run(): void
    {
        // The product property, end to end: a BD looking at a Tier 0 trail is refused, because the
        // search they would be vouching for has not happened yet.
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->discoveredLead($bd);

        $this->service()->recordCandidates($lead, [], ['osm_tag', 'wikidata']);
        $this->service()->markExhausted($lead);

        $this->postJson("/api/v1/crm/leads/{$lead->id}/website-absence")->assertStatus(409);
        $this->assertSame('website-unknown', $lead->refresh()->website_status);
    }

    public function test_a_complete_search_still_needs_a_human_to_conclude_absence(): void
    {
        // The gate tightens exhaustion; it must not have handed the machine a new way to conclude.
        $lead = $this->discoveredLead();

        $this->service()->recordCandidates($lead, [], WebsiteEvidenceService::REQUIRED_TIERS);
        $this->service()->markExhausted($lead);

        $this->assertTrue($this->service()->searchFor($lead)->isExhausted());
        $this->assertSame('website-unknown', $lead->refresh()->website_status);
    }

    public function test_a_bd_confirming_a_complete_search_still_produces_no_website(): void
    {
        // The happy path must survive the new guard: a refusal that also blocks legitimate use is
        // not a safety feature.
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->discoveredLead($bd);

        $this->service()->recordCandidates($lead, [], WebsiteEvidenceService::REQUIRED_TIERS);
        $this->service()->markExhausted($lead);

        $this->postJson("/api/v1/crm/leads/{$lead->id}/website-absence", [
            'note' => 'Called the clinic — they confirmed they have no website.',
        ])->assertOk()->assertJsonPath('data.websiteStatus', 'no-website');

        $this->assertSame('no-website', $lead->refresh()->website_status);
    }

    public function test_an_unchecked_candidate_still_blocks_exhaustion_on_a_complete_search(): void
    {
        // The older guard is independent of this one; covering every tier does not excuse a
        // candidate nobody tested.
        $lead = $this->discoveredLead();

        $this->service()->recordCandidates($lead, [
            ['url' => 'https://vigandental.example', 'source' => LeadWebsiteCandidate::SOURCE_PROBE],
        ], WebsiteEvidenceService::REQUIRED_TIERS);

        $this->service()->markExhausted($lead);

        $this->assertFalse($this->service()->searchFor($lead)->isExhausted());
    }

    // --- the new tier's provenance ----------------------------------------------

    public function test_a_probe_candidate_is_accepted_and_keeps_its_provenance(): void
    {
        Sanctum::actingAs($this->user('crm_admin'));
        $lead = $this->discoveredLead();

        $this->postJson("/api/v1/crm/leads/{$lead->id}/website-candidates", [
            'candidates' => [[
                'url' => 'https://vigandental.ph',
                'source' => LeadWebsiteCandidate::SOURCE_PROBE,
                'source_detail' => 'dns:vigandental.ph',
            ]],
            'tiers_run' => WebsiteEvidenceService::REQUIRED_TIERS,
        ])->assertOk()
            ->assertJsonPath('data.candidates.0.source', 'probe')
            ->assertJsonPath('data.candidates.0.sourceDetail', 'dns:vigandental.ph')
            // Proposed, not proven — a resolving host is a guess the verifier must still accept.
            ->assertJsonPath('data.candidates.0.verified', null);
    }

    public function test_a_probe_candidate_reopens_an_exhausted_search(): void
    {
        // A guess that resolves is new material, and a lead cannot stay "exhausted" while an
        // unchecked candidate sits against it — whichever tier proposed it.
        $lead = $this->discoveredLead();

        $this->service()->recordCandidates($lead, [], WebsiteEvidenceService::REQUIRED_TIERS);
        $this->service()->markExhausted($lead);
        $this->assertTrue($this->service()->searchFor($lead)->isExhausted());

        $this->service()->recordCandidates($lead, [
            ['url' => 'https://vigandental.ph', 'source' => LeadWebsiteCandidate::SOURCE_PROBE],
        ], [LeadWebsiteCandidate::SOURCE_PROBE]);

        $this->assertFalse($this->service()->searchFor($lead)->isExhausted());
    }
}
