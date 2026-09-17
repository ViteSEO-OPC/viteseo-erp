<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadWebsiteCandidate;
use App\Modules\Crm\Resources\NearbyClientResource;
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
            'phone' => '+63 77 722 1234',
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

    private function strongFindings(string $url = 'https://vigandentalcare.ph'): array
    {
        return [
            'website_reachable' => true,
            'website_title' => 'Vigan Dental Care — Dentist in Vigan',
            'website_description' => 'Family dentistry in Vigan.',
            'final_url' => $url,
            'canonical_url' => $url,
            'phones_found' => ['+63 77 722 1234'],
            'emails_found' => [],
        ];
    }

    private function weakFindings(string $url = 'https://unrelated-shop.example'): array
    {
        return [
            'website_reachable' => true,
            'website_title' => 'Welcome to our store',
            'website_description' => 'General merchandise.',
            'final_url' => $url,
            'canonical_url' => $url,
            'phones_found' => [],
            'emails_found' => [],
        ];
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

    // --- automatic determination ------------------------------------------------

    public function test_a_genuinely_exhausted_search_determines_absence_by_itself(): void
    {
        // The product change: the BD consumes a classification, they do not adjudicate DNS answers.
        $lead = $this->discoveredLead();

        $this->service()->recordCandidates($lead, [], WebsiteEvidenceService::REQUIRED_TIERS);
        $this->service()->markExhausted($lead);

        $this->assertSame('no-website', $lead->refresh()->website_status);
        $this->assertSame('high', (new NearbyClientResource($lead))->toArray(request())['priority']);
    }

    public function test_a_live_candidate_refused_on_identity_withholds_the_absence_determination(): void
    {
        // A REFUSED CANDIDATE IS NOT AN ABSENT ONE. Exhaustion says every tier ran and nothing was
        // VERIFIED; it does not say nothing was FOUND. A live page answered, bearing this business's
        // name closely enough for a tier to propose it, and the verifier could not confirm it belonged
        // to them — that is "we do not know", not "there is nothing".
        //
        // MEASURED: of the first 41 automatic determinations on the live pool, 12 held a live
        // name-matching domain refused on identity alone. Matabungkay Beach Hotel was told it had no
        // website while `matabungkaybeachhotel.com` was answering.
        $lead = $this->discoveredLead();

        $this->service()->recordCandidates($lead, [
            ['url' => 'https://vigandentalcare.ph', 'source' => LeadWebsiteCandidate::SOURCE_PROBE],
        ], WebsiteEvidenceService::REQUIRED_TIERS);

        // A reachable page the verifier cannot tie to this business.
        $this->service()->recordVerification(
            LeadWebsiteCandidate::query()->where('lead_id', $lead->id)->firstOrFail(),
            $this->weakFindings('https://vigandentalcare.ph'),
        );
        $this->service()->markExhausted($lead);

        // The SEARCH still exhausts — there is nothing further to try, and that is evidence.
        $this->assertTrue($this->service()->searchFor($lead)->isExhausted());
        // The CONCLUSION is withheld. Insufficient evidence stays `website-unknown`.
        $this->assertSame('website-unknown', $lead->refresh()->website_status);
        $this->assertNull($this->service()->searchFor($lead)->confirmed_absent_at);
    }

    public function test_an_unreachable_candidate_does_not_withhold_the_determination(): void
    {
        // A name resolved and nothing answered. That is not a website, and it does not weaken an
        // absence claim — the distinction the guard above turns on.
        $lead = $this->discoveredLead();

        $this->service()->recordCandidates($lead, [
            ['url' => 'https://vigandentalcare.ph', 'source' => LeadWebsiteCandidate::SOURCE_PROBE],
        ], WebsiteEvidenceService::REQUIRED_TIERS);

        $this->service()->recordVerification(
            LeadWebsiteCandidate::query()->where('lead_id', $lead->id)->firstOrFail(),
            ['website_reachable' => false, 'final_url' => null, 'canonical_url' => null,
             'website_title' => null, 'website_description' => null,
             'phones_found' => [], 'emails_found' => []],
        );
        $this->service()->markExhausted($lead);

        $this->assertSame('no-website', $lead->refresh()->website_status);
    }

    public function test_the_determination_is_attributed_to_nobody(): void
    {
        // NULL `confirmed_by` is the marker distinguishing a machine determination from a signature.
        $lead = $this->discoveredLead();

        $this->service()->recordCandidates($lead, [], WebsiteEvidenceService::REQUIRED_TIERS);
        $search = $this->service()->markExhausted($lead);

        $this->assertNotNull($search->confirmed_absent_at);
        $this->assertNull($search->confirmed_by);
    }

    public function test_an_incomplete_search_determines_nothing(): void
    {
        // The guard that matters most: a missing tier must leave the lead exactly where it was.
        $lead = $this->discoveredLead();

        $this->service()->recordCandidates($lead, [], ['osm_tag', 'wikidata']);
        $this->service()->markExhausted($lead);

        $this->assertSame('website-unknown', $lead->refresh()->website_status);
    }

    public function test_an_unchecked_candidate_prevents_any_determination(): void
    {
        $lead = $this->discoveredLead();

        $this->service()->recordCandidates($lead, [
            ['url' => 'https://vigandental.example', 'source' => LeadWebsiteCandidate::SOURCE_PROBE],
        ], WebsiteEvidenceService::REQUIRED_TIERS);
        $this->service()->markExhausted($lead);

        $this->assertSame('website-unknown', $lead->refresh()->website_status);
    }

    public function test_a_refused_candidate_does_not_block_exhaustion_but_does_block_the_conclusion(): void
    {
        // THIS TEST USED TO ASSERT `no-website` HERE, on the reasoning that a candidate found and
        // DISPROVED is stronger evidence than none at all. The first half is right and is still
        // pinned below: a refused candidate must not stall the SEARCH the way an unchecked one does.
        //
        // The second half conflated two different refusals. `weakFindings()` is a page that ANSWERED
        // and could not be tied to this business — "not proved theirs", not "disproved". On the live
        // pool that same path refused `matabungkaybeachhotel.com` for Matabungkay Beach Hotel, and
        // the lead was then told it had no website while its website was answering. Twelve of the
        // first forty-one automatic determinations were that shape.
        //
        // So: the search exhausts, the evidence stands, and the machine declines to conclude. A human
        // can still confirm absence against the trail through confirmAbsent().
        $lead = $this->discoveredLead();

        $this->service()->recordCandidates($lead, [
            ['url' => 'https://unrelated-shop.example', 'source' => LeadWebsiteCandidate::SOURCE_PROBE],
        ], WebsiteEvidenceService::REQUIRED_TIERS);
        $candidate = LeadWebsiteCandidate::query()->where('lead_id', $lead->id)->firstOrFail();
        $this->service()->recordVerification($candidate, $this->weakFindings());

        $this->service()->markExhausted($lead);

        $this->assertFalse($candidate->refresh()->verified);
        $this->assertTrue($this->service()->searchFor($lead)->isExhausted());
        $this->assertSame('website-unknown', $lead->refresh()->website_status);
    }

    public function test_it_never_overwrites_a_status_somebody_else_set(): void
    {
        // A lead that is not `website-unknown` holds a human's or an audit's answer. Filling a gap is
        // not the same as settling a disagreement.
        $lead = $this->discoveredLead();
        $lead->update(['website_status' => 'needs-redesign']);

        $this->service()->recordCandidates($lead, [], WebsiteEvidenceService::REQUIRED_TIERS);
        $this->service()->markExhausted($lead);

        $this->assertSame('needs-redesign', $lead->refresh()->website_status);
    }

    public function test_a_later_verified_website_withdraws_the_automatic_determination(): void
    {
        // What makes an automatic conclusion safe to make at all: it is reversible by evidence.
        $lead = $this->discoveredLead();
        $this->service()->recordCandidates($lead, [], WebsiteEvidenceService::REQUIRED_TIERS);
        $this->service()->markExhausted($lead);
        $this->assertSame('no-website', $lead->refresh()->website_status);

        $this->service()->recordCandidates($lead, [
            ['url' => 'https://vigandentalcare.ph', 'source' => LeadWebsiteCandidate::SOURCE_PROBE],
        ], WebsiteEvidenceService::REQUIRED_TIERS);
        $candidate = LeadWebsiteCandidate::query()
            ->where('lead_id', $lead->id)->where('verified', null)->firstOrFail();
        $this->service()->recordVerification($candidate, $this->strongFindings('https://vigandentalcare.ph'));

        $this->assertTrue($candidate->refresh()->verified);
        $this->assertSame('website-unknown', $lead->refresh()->website_status);
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
