<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadWebsiteCandidate;
use App\Modules\Crm\Models\LeadWebsiteSearch;
use App\Modules\Crm\Resources\NearbyClientResource;
use App\Modules\Crm\Services\Website\WebsiteEvidenceService;
use App\Modules\Crm\Services\WebsiteIdentityVerifier;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ActsAsNerbyService;
use Tests\TestCase;

/**
 * The evidence chain that lets `website-unknown` legitimately become `no-website`.
 *
 *   OSM candidate -> website-unknown -> candidate discovery -> deterministic verification
 *   -> exhausted evidence -> BD confirms -> no-website -> existing High Priority
 *
 * The property these defend is narrow and important: **the system may not conclude absence on its
 * own.** A search that finds nothing is weaker evidence than a human check — a business may hold a
 * site no tier surfaced — so exhaustion is recorded as evidence and stops there. Every attempt to
 * reach `no-website` without a person, or without evidence, must be refused.
 *
 * The opposite failure matters too and is tested: a confirmation that cannot be made because the
 * search was never run is not a safety feature if it also blocks legitimate use, so the happy path
 * is pinned end to end.
 */
class WebsiteEvidenceChainTest extends TestCase
{
    use ActsAsNerbyService;
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

    /** Crawl findings that will clear the stricter bar: domain + title + phone all match. */
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

    /** Findings for a page that plainly belongs to somebody else. */
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

    // --- the queue -----------------------------------------------------------

    public function test_the_queue_offers_discovered_leads_that_have_no_url(): void
    {
        $this->actingAsNerbyService();
        $lead = $this->discoveredLead();

        $this->getJson('/api/v1/crm/website-evidence/queue')->assertOk()
            ->assertJsonPath('data.0.id', (string) $lead->id)
            ->assertJsonPath('data.0.business', 'Vigan Dental Care');
    }

    public function test_the_queue_carries_the_coordinates_tier_0_needs_to_find_an_osm_element(): void
    {
        // WHY THE QUEUE SENDS A POSITION. Tier 0 reads an OSM element and finds it by id. A Google
        // lead's `places/ChIJ…` is not an OSM id and cannot be converted into one, so for that entire
        // pool Tier 0 had nowhere to look, `tiers_run` was honestly empty forever, and the lead could
        // never reach a genuine exhaustion however absent its website really was. Coordinates are the
        // other way to reach the same element — already captured at ingest, sent here so the tier has
        // somewhere to look.
        $this->actingAsNerbyService();
        $lead = $this->discoveredLead();
        $lead->update([
            'place_id' => 'places/ChIJ_anF5hvJlzMR9ku',
            'latitude' => 14.5995,
            'longitude' => 120.9842,
        ]);

        $this->getJson('/api/v1/crm/website-evidence/queue')->assertOk()
            ->assertJsonPath('data.0.placeId', 'places/ChIJ_anF5hvJlzMR9ku')
            ->assertJsonPath('data.0.latitude', 14.5995)
            ->assertJsonPath('data.0.longitude', 120.9842);
    }

    public function test_a_lead_without_coordinates_is_still_queued_with_nulls(): void
    {
        // Absence of a position is not a reason to withhold the lead: the probe tier does not need
        // one, and the resolver simply cannot match it. It must not become a null-pointer either.
        $this->actingAsNerbyService();
        $this->discoveredLead()->update(['latitude' => null, 'longitude' => null]);

        $this->getJson('/api/v1/crm/website-evidence/queue')->assertOk()
            ->assertJsonPath('data.0.latitude', null)
            ->assertJsonPath('data.0.longitude', null);
    }

    public function test_a_lead_that_already_has_a_url_is_not_queued(): void
    {
        // It has a URL, so the ORDINARY audit owns it. Searching for a website it already has would
        // be wasted spend.
        $this->actingAsNerbyService();
        $this->discoveredLead()->update(['website' => 'https://example.ph']);

        $this->getJson('/api/v1/crm/website-evidence/queue')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_an_exhausted_lead_leaves_the_queue(): void
    {
        $this->actingAsNerbyService();
        $lead = $this->discoveredLead();
        $this->service()->recordCandidates($lead, [], WebsiteEvidenceService::REQUIRED_TIERS);
        $this->service()->markExhausted($lead);

        $this->getJson('/api/v1/crm/website-evidence/queue')->assertOk()->assertJsonCount(0, 'data');
    }

    // --- recording candidates ------------------------------------------------

    public function test_candidates_are_recorded_with_their_provenance(): void
    {
        $this->actingAsNerbyService();
        $lead = $this->discoveredLead();

        $this->postJson("/api/v1/crm/leads/{$lead->id}/website-candidates", [
            'candidates' => [
                ['url' => 'https://mercurydrug.com', 'source' => 'wikidata', 'source_detail' => 'Q6818610'],
            ],
            'tiers_run' => ['osm_tag', 'wikidata'],
        ])->assertOk()
            ->assertJsonPath('data.candidates.0.source', 'wikidata')
            ->assertJsonPath('data.candidates.0.sourceDetail', 'Q6818610')
            // Proposed but not yet checked — deliberately NOT false, which would read as refuted.
            ->assertJsonPath('data.candidates.0.verified', null)
            ->assertJsonPath('data.search.tiersRun', ['osm_tag', 'wikidata']);
    }

    public function test_a_search_that_found_nothing_is_still_recorded_as_evidence(): void
    {
        // "We ran Wikidata and it had nothing" is a fact worth keeping. An empty submission that
        // recorded nothing would be indistinguishable from never having looked.
        $this->actingAsNerbyService();
        $lead = $this->discoveredLead();

        $this->postJson("/api/v1/crm/leads/{$lead->id}/website-candidates", [
            'candidates' => [], 'tiers_run' => ['osm_tag', 'wikidata'],
        ])->assertOk()
            ->assertJsonPath('data.search.searched', true)
            ->assertJsonPath('data.search.candidatesFound', 0)
            ->assertJsonPath('data.search.tiersRun', ['osm_tag', 'wikidata']);
    }

    public function test_re_running_a_tier_updates_rather_than_duplicates(): void
    {
        $lead = $this->discoveredLead();
        $payload = [['url' => 'https://a.example', 'source' => 'wikidata']];

        $this->service()->recordCandidates($lead, $payload, ['wikidata']);
        $this->service()->recordCandidates($lead, $payload, ['wikidata']);

        // Otherwise a repeated search would inflate the count and make thin evidence look thorough.
        $this->assertSame(1, LeadWebsiteCandidate::query()->where('lead_id', $lead->id)->count());
    }

    public function test_the_same_url_from_two_tiers_is_two_rows(): void
    {
        // Independent corroboration is stronger than one source, and collapsing them would erase it.
        $lead = $this->discoveredLead();

        $this->service()->recordCandidates($lead, [
            ['url' => 'https://a.example', 'source' => 'wikidata'],
            ['url' => 'https://a.example', 'source' => 'osm_tag'],
        ], ['wikidata', 'osm_tag']);

        $this->assertSame(2, LeadWebsiteCandidate::query()->where('lead_id', $lead->id)->count());
    }

    public function test_tiers_run_accumulates_across_passes(): void
    {
        $lead = $this->discoveredLead();

        $this->service()->recordCandidates($lead, [], ['osm_tag']);
        $search = $this->service()->recordCandidates($lead, [], ['wikidata']);

        // A later pass must not erase the record that an earlier tier ran, or an exhaustion claim
        // would understate what was actually tried.
        $this->assertEqualsCanonicalizing(['osm_tag', 'wikidata'], $search->tiers_run);
    }

    // --- deterministic verification -----------------------------------------

    public function test_a_strong_candidate_is_verified(): void
    {
        $this->actingAsNerbyService();
        $lead = $this->discoveredLead();
        $this->service()->recordCandidates($lead, [
            ['url' => 'https://vigandentalcare.ph', 'source' => 'wikidata'],
        ], ['wikidata']);
        $candidate = LeadWebsiteCandidate::query()->where('lead_id', $lead->id)->firstOrFail();

        $this->putJson(
            "/api/v1/crm/leads/{$lead->id}/website-candidates/{$candidate->id}",
            $this->strongFindings(),
        )->assertOk()
            ->assertJsonPath('data.candidates.0.verified', true)
            ->assertJsonPath('data.candidates.0.rejectionReason', null);
    }

    public function test_a_candidate_that_belongs_to_someone_else_is_refused(): void
    {
        $lead = $this->discoveredLead();
        $this->service()->recordCandidates($lead, [
            ['url' => 'https://unrelated-shop.example', 'source' => 'search'],
        ], ['search']);
        $candidate = LeadWebsiteCandidate::query()->where('lead_id', $lead->id)->firstOrFail();

        $this->service()->recordVerification($candidate, $this->weakFindings());

        $this->assertFalse($candidate->refresh()->verified);
        $this->assertSame(LeadWebsiteCandidate::REJECTED_IDENTITY, $candidate->rejection_reason);
    }

    public function test_an_unreachable_candidate_is_refused_as_unreachable(): void
    {
        // A stale URL — the measured Max's Restaurant case, whose domain lapsed and now serves
        // somebody else. It must be refused, not recorded as a website.
        $lead = $this->discoveredLead();
        $this->service()->recordCandidates($lead, [
            ['url' => 'https://lapsed-domain.example', 'source' => 'wikidata'],
        ], ['wikidata']);
        $candidate = LeadWebsiteCandidate::query()->where('lead_id', $lead->id)->firstOrFail();

        $this->service()->recordVerification($candidate, ['website_reachable' => false]);

        $this->assertFalse($candidate->refresh()->verified);
        $this->assertSame(LeadWebsiteCandidate::REJECTED_UNREACHABLE, $candidate->rejection_reason);
    }

    public function test_the_discovered_bar_is_stricter_than_the_audit_threshold(): void
    {
        // A guessed URL must prove more than one somebody asserted. This is the whole reason the
        // constant exists rather than reusing the verifier's own threshold.
        $this->assertGreaterThan(
            WebsiteIdentityVerifier::THRESHOLD,
            WebsiteEvidenceService::ACCEPT_CONFIDENCE,
        );
    }

    public function test_two_close_candidates_on_different_domains_are_both_refused(): void
    {
        // 66 vs 65 is a coin toss between two plausible pages, not an answer. Ambiguity must resolve
        // to "keep looking" rather than to whichever sorted first.
        $lead = $this->discoveredLead();
        $this->service()->recordCandidates($lead, [
            ['url' => 'https://vigandentalcare.ph', 'source' => 'wikidata'],
            ['url' => 'https://vigandentalcare.com', 'source' => 'search'],
        ], ['wikidata', 'search']);

        foreach (LeadWebsiteCandidate::query()->where('lead_id', $lead->id)->get() as $candidate) {
            $this->service()->recordVerification($candidate, $this->strongFindings($candidate->url));
        }

        $verified = LeadWebsiteCandidate::query()->where('lead_id', $lead->id)->where('verified', true)->count();
        $this->assertSame(0, $verified, 'an ambiguous winner must not be accepted');
        $this->assertSame(
            LeadWebsiteCandidate::REJECTED_MARGIN,
            LeadWebsiteCandidate::query()->where('lead_id', $lead->id)->first()->rejection_reason,
        );
    }

    // --- exhaustion is evidence, not a verdict -------------------------------

    public function test_a_genuine_exhaustion_concludes_absence_by_itself(): void
    {
        $lead = $this->discoveredLead();
        $this->service()->recordCandidates($lead, [], WebsiteEvidenceService::REQUIRED_TIERS);

        $this->service()->markExhausted($lead);

        // THE CENTRAL GUARANTEE, INVERTED BY DESIGN. Searching every required tier and verifying
        // nothing IS the conclusion — the BD consumes it rather than adjudicating the evidence.
        $this->assertSame('no-website', $lead->refresh()->website_status);
        $this->assertTrue($this->service()->searchFor($lead)->isExhausted());
        // Nobody signed it: NULL `confirmed_by` marks a determination rather than a signature.
        $this->assertNull($this->service()->searchFor($lead)->confirmed_by);
    }

    public function test_exhaustion_is_refused_while_a_candidate_is_unchecked(): void
    {
        // An exhaustion claim resting on candidates nobody tested would be worthless.
        $lead = $this->discoveredLead();
        $this->service()->recordCandidates($lead, [
            ['url' => 'https://a.example', 'source' => 'wikidata'],
        ], WebsiteEvidenceService::REQUIRED_TIERS);

        $this->service()->markExhausted($lead);

        $this->assertFalse($this->service()->searchFor($lead)->isExhausted());
    }

    public function test_a_new_candidate_reopens_an_exhausted_search(): void
    {
        $lead = $this->discoveredLead();
        $this->service()->recordCandidates($lead, [], WebsiteEvidenceService::REQUIRED_TIERS);
        $this->service()->markExhausted($lead);
        $this->assertTrue($this->service()->searchFor($lead)->isExhausted());

        $this->service()->recordCandidates($lead, [
            ['url' => 'https://new.example', 'source' => 'search'],
        ], ['search']);

        $this->assertFalse($this->service()->searchFor($lead)->isExhausted());
    }

    // --- the human confirmation, and its guards ------------------------------

    public function test_confirmation_is_refused_before_the_search_is_exhausted(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->discoveredLead($bd);

        $this->postJson("/api/v1/crm/leads/{$lead->id}/website-absence")
            ->assertStatus(409);

        $this->assertSame('website-unknown', $lead->refresh()->website_status);
    }

    public function test_confirmation_is_refused_when_a_verified_website_exists(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->discoveredLead($bd);

        $this->service()->recordCandidates($lead, [
            ['url' => 'https://vigandentalcare.ph', 'source' => 'wikidata'],
        ], ['wikidata']);
        $candidate = LeadWebsiteCandidate::query()->where('lead_id', $lead->id)->firstOrFail();
        $this->service()->recordVerification($candidate, $this->strongFindings());
        // Force the flag so the guard, not the exhaustion rule, is what refuses.
        LeadWebsiteSearch::query()->where('lead_id', $lead->id)->update(['exhausted_at' => now()]);

        $this->postJson("/api/v1/crm/leads/{$lead->id}/website-absence")->assertStatus(409);
        $this->assertSame('website-unknown', $lead->refresh()->website_status);
    }

    public function test_a_bd_confirming_exhausted_evidence_produces_no_website(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->discoveredLead($bd);

        // A candidate was found, checked, and refused. Then the tiers ran out.
        $this->service()->recordCandidates($lead, [
            ['url' => 'https://unrelated-shop.example', 'source' => 'search'],
        ], [...WebsiteEvidenceService::REQUIRED_TIERS, 'search']);
        $candidate = LeadWebsiteCandidate::query()->where('lead_id', $lead->id)->firstOrFail();
        $this->service()->recordVerification($candidate, $this->weakFindings());
        $this->service()->markExhausted($lead);

        $this->postJson("/api/v1/crm/leads/{$lead->id}/website-absence", [
            'note' => 'Called the clinic — they confirmed they have no website.',
        ])->assertOk()
            ->assertJsonPath('data.websiteStatus', 'no-website')
            ->assertJsonPath('data.search.confirmedAbsent', true)
            ->assertJsonPath('data.search.confirmedBy', $bd->name);

        // THE OUTCOME THIS FEATURE EXISTS FOR.
        $this->assertSame('no-website', $lead->refresh()->website_status);
    }

    public function test_the_confirmed_lead_renders_high_on_the_nearby_board(): void
    {
        // The existing NearbyClientResource mapping, untouched: no-website -> high. This is the end
        // of the chain, and it needed no change to the priority rules to arrive.
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->discoveredLead($bd);

        $this->service()->recordCandidates($lead, [], WebsiteEvidenceService::REQUIRED_TIERS);
        $this->service()->markExhausted($lead);
        $this->postJson("/api/v1/crm/leads/{$lead->id}/website-absence")->assertOk();

        $row = collect($this->getJson('/api/v1/crm/nearby-clients')->assertOk()->json('data'))
            ->firstWhere('id', (string) $lead->id);

        $this->assertSame('high', $row['priority']);
        $this->assertSame('no-website', $row['website']['status']);
    }

    public function test_the_evidence_trail_is_readable_by_the_owning_bd(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->discoveredLead($bd);
        $this->service()->recordCandidates($lead, [
            ['url' => 'https://unrelated-shop.example', 'source' => 'search'],
        ], ['search']);

        $this->getJson("/api/v1/crm/leads/{$lead->id}/website-evidence")->assertOk()
            ->assertJsonPath('data.candidates.0.url', 'https://unrelated-shop.example')
            ->assertJsonPath('data.search.canConfirmAbsence', false);
    }

    public function test_only_an_exhausted_search_advertises_that_absence_can_be_confirmed(): void
    {
        Sanctum::actingAs($this->user('crm_admin'));
        $lead = $this->discoveredLead();
        $this->service()->recordCandidates($lead, [], WebsiteEvidenceService::REQUIRED_TIERS);
        $this->service()->markExhausted($lead);

        // The system already concluded, so there is nothing left for a person to confirm — which is
        // the point: the BD is not asked to adjudicate DNS answers and identity confidences.
        $this->getJson("/api/v1/crm/leads/{$lead->id}/website-evidence")->assertOk()
            ->assertJsonPath('data.search.confirmedAbsent', true)
            ->assertJsonPath('data.search.canConfirmAbsence', false);
    }

    // --- boundaries ----------------------------------------------------------

    public function test_a_candidate_cannot_be_verified_against_a_different_lead(): void
    {
        $this->actingAsNerbyService();
        $lead = $this->discoveredLead();
        $other = $this->discoveredLead();
        $this->service()->recordCandidates($other, [
            ['url' => 'https://a.example', 'source' => 'wikidata'],
        ], ['wikidata']);
        $candidate = LeadWebsiteCandidate::query()->where('lead_id', $other->id)->firstOrFail();

        // Otherwise a candidate would be adjudicated against the wrong business's name and phone.
        $this->putJson(
            "/api/v1/crm/leads/{$lead->id}/website-candidates/{$candidate->id}",
            $this->strongFindings(),
        )->assertNotFound();
    }

    public function test_an_unauthenticated_caller_is_refused_everywhere(): void
    {
        $lead = $this->discoveredLead();

        $this->getJson('/api/v1/crm/website-evidence/queue')->assertUnauthorized();
        $this->postJson("/api/v1/crm/leads/{$lead->id}/website-candidates", [])->assertUnauthorized();
        $this->getJson("/api/v1/crm/leads/{$lead->id}/website-evidence")->assertUnauthorized();
        $this->postJson("/api/v1/crm/leads/{$lead->id}/website-absence")->assertUnauthorized();
    }

    public function test_confirmation_does_not_touch_score_or_priority_directly(): void
    {
        // The lead's `priority` column and score are not this feature's business. `website_status`
        // is the only field it writes; anything downstream is the existing frozen behaviour.
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->discoveredLead($bd);
        $before = ['priority' => $lead->priority, 'status' => $lead->status, 'score' => $lead->score];

        $this->service()->recordCandidates($lead, [], WebsiteEvidenceService::REQUIRED_TIERS);
        $this->service()->markExhausted($lead);
        $this->postJson("/api/v1/crm/leads/{$lead->id}/website-absence")->assertOk();

        $after = $lead->refresh();
        $this->assertSame($before['priority'], $after->priority);
        $this->assertSame($before['status'], $after->status);
        // A prospect is outside Lead::scopeActive, so the observer does not score it either.
        $this->assertSame($before['score'], $after->score);
    }

    // --- who may act, on a pool that has no owners --------------------------

    public function test_a_bd_may_read_and_confirm_an_unassigned_prospect(): void
    {
        // THE REAL SHAPE OF THE POOL. A discovered prospect is unassigned until someone qualifies it,
        // so the ordinary `isVisibleTo` boundary would deny every BD on exactly the businesses this
        // workflow exists for. The prospect carve-out on the policy is what makes the feature usable
        // at all — not a loosening of the lead-ownership rule, which still applies once qualified.
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->discoveredLead();
        $this->assertNull($lead->assigned_to);

        $this->getJson("/api/v1/crm/leads/{$lead->id}/website-evidence")->assertOk();

        $this->service()->recordCandidates($lead, [], WebsiteEvidenceService::REQUIRED_TIERS);
        $this->service()->markExhausted($lead);

        $this->postJson("/api/v1/crm/leads/{$lead->id}/website-absence")->assertOk();
        $this->assertSame('no-website', $lead->refresh()->website_status);
    }

    public function test_a_bd_may_not_touch_a_qualified_lead_owned_by_someone_else(): void
    {
        // Once qualified the lead has an owner, and this endpoint is no more permissive than the
        // lead-edit endpoint whose authority it borrows.
        $owner = $this->user('crm_bd');
        $other = $this->user('crm_bd');
        $lead = $this->discoveredLead($owner);
        $lead->update(['qualified_at' => now()]);

        Sanctum::actingAs($other);
        $this->getJson("/api/v1/crm/leads/{$lead->id}/website-evidence")->assertForbidden();
        $this->postJson("/api/v1/crm/leads/{$lead->id}/website-absence")->assertForbidden();
    }

    public function test_a_lead_with_a_verified_candidate_leaves_the_search_queue(): void
    {
        // The chain deliberately never writes `crm_leads.website`, so the queue's "has no URL" filter
        // cannot notice that this lead was answered. Nothing else would take it out either: exhaustion
        // is refused while a candidate is verified. Without an explicit exclusion it would be searched
        // again on every run, forever, ahead of leads nobody has looked at yet.
        $this->actingAsNerbyService(); // the queues are the discovery service's (machine-only)
        $solved = $this->discoveredLead();
        $unsolved = $this->discoveredLead();

        $this->service()->recordCandidates($solved, [
            ['url' => 'https://vigandentalcare.ph', 'source' => 'wikidata'],
        ], ['wikidata']);
        $candidate = LeadWebsiteCandidate::query()->where('lead_id', $solved->id)->firstOrFail();
        $this->service()->recordVerification($candidate, $this->strongFindings());
        $this->assertTrue($candidate->refresh()->verified);

        $queued = $this->getJson('/api/v1/crm/website-evidence/queue?limit=50')
            ->assertOk()->json('data.*.id');

        $this->assertNotContains((string) $solved->id, $queued);
        $this->assertContains((string) $unsolved->id, $queued);
    }

    // --- a confirmed absence that later turns out to be wrong ----------------

    public function test_a_verified_website_withdraws_an_earlier_confirmed_absence(): void
    {
        // THE EXACT SEQUENCE: confirm absence -> no-website/High -> a website is later verified ->
        // no longer no-website/High. Before this, the lead kept the strongest signal on the Nearby
        // board while the evidence underneath it proved a website existed.
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->discoveredLead();

        // 1. Searched, nothing found, human confirms.
        $this->service()->recordCandidates($lead, [], WebsiteEvidenceService::REQUIRED_TIERS);
        $this->service()->markExhausted($lead);
        $this->postJson("/api/v1/crm/leads/{$lead->id}/website-absence")->assertOk();

        $this->assertSame('no-website', $lead->refresh()->website_status);
        $this->assertSame('high', $this->nearbyPriorityFor($lead));

        // 2. A later tier proposes a URL, and it verifies deterministically.
        $this->service()->recordCandidates($lead, [
            ['url' => 'https://vigandentalcare.ph', 'source' => 'search'],
        ], ['search']);
        $candidate = LeadWebsiteCandidate::query()->where('lead_id', $lead->id)->firstOrFail();
        $this->service()->recordVerification($candidate, $this->strongFindings());
        $this->assertTrue($candidate->refresh()->verified);

        // 3. The lead must no longer claim absence, and must no longer rank High on the board.
        $lead->refresh();
        $this->assertSame('website-unknown', $lead->website_status);
        $this->assertNotSame('high', $this->nearbyPriorityFor($lead));

        $search = $this->service()->searchFor($lead);
        $this->assertFalse($search->isConfirmedAbsent());
        $this->assertTrue($search->isConfirmationInvalidated());
        // The exhaustion claim goes too — a search holding a verified website plainly did not come
        // up empty.
        $this->assertFalse($search->isExhausted());
    }

    public function test_the_withdrawn_confirmation_is_kept_rather_than_erased(): void
    {
        // The BD's judgement was reasonable on the evidence they had. The trail must read
        // "confirmed, then superseded" — not as though nobody ever decided.
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->discoveredLead();

        $this->service()->recordCandidates($lead, [], WebsiteEvidenceService::REQUIRED_TIERS);
        $this->service()->markExhausted($lead);
        $this->postJson("/api/v1/crm/leads/{$lead->id}/website-absence", ['note' => 'Called them.'])
            ->assertOk();

        $this->service()->recordCandidates($lead, [
            ['url' => 'https://vigandentalcare.ph', 'source' => 'search'],
        ], ['search']);
        $candidate = LeadWebsiteCandidate::query()->where('lead_id', $lead->id)->firstOrFail();
        $this->service()->recordVerification($candidate, $this->strongFindings());

        $search = $this->service()->searchFor($lead->refresh());
        $this->assertNotNull($search->confirmed_absent_at);
        $this->assertSame($bd->id, $search->confirmed_by);
        $this->assertSame('Called them.', $search->confirmation_note);
        $this->assertNotNull($search->confirmation_invalidated_at);

        $response = $this->getJson("/api/v1/crm/leads/{$lead->id}/website-evidence")->assertOk();
        $response->assertJsonPath('data.search.confirmedAbsent', false);
        $response->assertJsonPath('data.search.confirmationWithdrawn', true);
        $this->assertNotNull($response->json('data.search.withdrawnReason'));
    }

    public function test_a_refused_candidate_leaves_a_confirmed_absence_standing(): void
    {
        // Only a VERIFIED website may overturn a human conclusion. A candidate that was checked and
        // rejected is the evidence that supported the conclusion in the first place.
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->discoveredLead();

        $this->service()->recordCandidates($lead, [], WebsiteEvidenceService::REQUIRED_TIERS);
        $this->service()->markExhausted($lead);
        $this->postJson("/api/v1/crm/leads/{$lead->id}/website-absence")->assertOk();

        $this->service()->recordCandidates($lead, [
            ['url' => 'https://someone-else.ph', 'source' => 'search'],
        ], ['search']);
        $candidate = LeadWebsiteCandidate::query()->where('lead_id', $lead->id)->firstOrFail();
        $this->service()->recordVerification($candidate, $this->foreignFindings());

        $this->assertFalse($candidate->refresh()->verified);
        $this->assertSame('no-website', $lead->refresh()->website_status);
        $this->assertTrue($this->service()->searchFor($lead)->isConfirmedAbsent());
    }

    public function test_ambiguous_candidates_leave_the_lead_neutral_and_never_restore_no_website(): void
    {
        // A ONE-WAY DOOR, DELIBERATELY. Candidates verify one at a time, so a lead can pass through a
        // moment where one candidate is verified — withdrawing the confirmation — before a second
        // arrives and the margin rule refuses both. The confirmation is NOT restored afterwards, and
        // that is the point: restoring it would mean writing `no-website` automatically, which is
        // exactly the thing nothing in this system is allowed to do. The lead settles at neutral and
        // a human may confirm again, having seen the two candidates that caused the doubt.
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->discoveredLead();

        $this->service()->recordCandidates($lead, [], WebsiteEvidenceService::REQUIRED_TIERS);
        $this->service()->markExhausted($lead);
        $this->postJson("/api/v1/crm/leads/{$lead->id}/website-absence")->assertOk();
        $this->assertSame('no-website', $lead->refresh()->website_status);

        // Two strong candidates on different domains — neither can be told apart, so both are refused.
        $this->service()->recordCandidates($lead, [
            ['url' => 'https://vigandentalcare.ph', 'source' => 'search'],
            ['url' => 'https://vigandentalcare.com', 'source' => 'wikidata'],
        ], ['search', 'wikidata']);

        foreach (LeadWebsiteCandidate::query()->where('lead_id', $lead->id)->get() as $candidate) {
            $this->service()->recordVerification($candidate, $this->strongFindings($candidate->url));
        }

        // Nothing survived verification...
        $this->assertSame(
            0,
            LeadWebsiteCandidate::query()->where('lead_id', $lead->id)->where('verified', true)->count(),
        );
        // ...and the lead is neutral, NOT back at no-website. Nothing automated may write that value.
        $this->assertSame('website-unknown', $lead->refresh()->website_status);
        $this->assertNotSame('high', $this->nearbyPriorityFor($lead));
        $this->assertFalse($this->service()->searchFor($lead)->isConfirmedAbsent());
    }

    public function test_the_withdrawal_never_writes_no_website_and_leaves_a_human_status_alone(): void
    {
        // Two guarantees in one: the reconciliation only ever reverts AWAY from `no-website`, and it
        // will not stomp a status a person set more recently than the confirmation it is unwinding.
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->discoveredLead();

        $this->service()->recordCandidates($lead, [], WebsiteEvidenceService::REQUIRED_TIERS);
        $this->service()->markExhausted($lead);
        $this->postJson("/api/v1/crm/leads/{$lead->id}/website-absence")->assertOk();

        // A human moves it on before the verification lands.
        $lead->update(['website_status' => 'needs-redesign']);

        $this->service()->recordCandidates($lead, [
            ['url' => 'https://vigandentalcare.ph', 'source' => 'search'],
        ], ['search']);
        $candidate = LeadWebsiteCandidate::query()->where('lead_id', $lead->id)->firstOrFail();
        $this->service()->recordVerification($candidate, $this->strongFindings());

        $this->assertSame('needs-redesign', $lead->refresh()->website_status);
        $this->assertTrue($this->service()->searchFor($lead)->isConfirmationInvalidated());
    }

    public function test_the_safety_rule_still_holds_after_a_withdrawal(): void
    {
        // Regression guard on the original invariant: an unchecked candidate must block confirmation.
        // Re-asserted here because the withdrawal path now clears `exhausted_at`, and clearing it
        // must NOT open a shortcut back to `no-website`.
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->discoveredLead();

        $this->service()->recordCandidates($lead, [
            ['url' => 'https://maybe.ph', 'source' => 'search'],
        ], ['search']);

        // Claiming exhaustion while a candidate is unchecked is refused...
        $this->assertFalse($this->service()->markExhausted($lead)->isExhausted());

        // ...and so, therefore, is the confirmation.
        $this->postJson("/api/v1/crm/leads/{$lead->id}/website-absence")->assertStatus(409);
        $this->assertSame('website-unknown', $lead->refresh()->website_status);
    }

    /** The prospecting priority the Nearby board would render for this lead. */
    private function nearbyPriorityFor(Lead $lead): string
    {
        return (new NearbyClientResource($lead->refresh()))
            ->toArray(request())['priority'];
    }

    /** Crawl findings for a page that is plainly a different business. */
    private function foreignFindings(): array
    {
        return [
            'website_reachable' => true,
            'website_title' => 'Manila Hardware Supply',
            'website_description' => 'Tools and paint.',
            'final_url' => 'https://someone-else.ph',
            'canonical_url' => 'https://someone-else.ph',
        ];
    }

    // --- a verified candidate becomes the lead's website ---------------------

    public function test_a_verified_candidate_is_promoted_to_the_leads_website(): void
    {
        // THE SEAM THIS CLOSES. `crm_leads.website` is what ROUTES a lead between the two queues. A
        // lead holding a verified candidate satisfied neither: answered for the evidence queue, and
        // invisible to the audit queue because the column was still null.
        $lead = $this->discoveredLead();
        $this->assertNull($lead->website);

        $this->service()->recordCandidates($lead, [
            ['url' => 'https://vigandentalcare.ph', 'source' => 'wikidata'],
        ], ['wikidata']);
        $candidate = LeadWebsiteCandidate::query()->where('lead_id', $lead->id)->firstOrFail();
        $this->service()->recordVerification($candidate, $this->strongFindings());

        $this->assertTrue($candidate->refresh()->verified);
        $this->assertSame('https://vigandentalcare.ph', $lead->refresh()->website);
    }

    public function test_promotion_leaves_the_evidence_record_completely_intact(): void
    {
        // The candidate row remains the audit trail. Promotion copies the URL onward; it never
        // becomes the record of WHY the URL is trusted.
        $lead = $this->discoveredLead();
        $this->service()->recordCandidates($lead, [
            ['url' => 'https://vigandentalcare.ph', 'source' => 'wikidata'],
        ], ['wikidata']);
        $candidate = LeadWebsiteCandidate::query()->where('lead_id', $lead->id)->firstOrFail();
        $this->service()->recordVerification($candidate, $this->strongFindings());

        $candidate->refresh();
        $this->assertTrue($candidate->verified);
        $this->assertSame('https://vigandentalcare.ph', $candidate->url);
        $this->assertNotNull($candidate->normalized_url);
        $this->assertGreaterThanOrEqual(WebsiteEvidenceService::ACCEPT_CONFIDENCE, $candidate->confidence);
        $this->assertNotEmpty($candidate->signals);
        $this->assertNotNull($candidate->checked_at);
        $this->assertNull($candidate->rejection_reason);
    }

    public function test_an_unverified_candidate_is_never_promoted(): void
    {
        // A page that belongs to somebody else is refused, and the lead keeps no URL at all.
        $lead = $this->discoveredLead();
        $this->service()->recordCandidates($lead, [
            ['url' => 'https://someone-else.ph', 'source' => 'search'],
        ], ['search']);
        $candidate = LeadWebsiteCandidate::query()->where('lead_id', $lead->id)->firstOrFail();
        $this->service()->recordVerification($candidate, $this->foreignFindings());

        $this->assertFalse($candidate->refresh()->verified);
        $this->assertNull($lead->refresh()->website);
    }

    public function test_a_candidate_the_margin_rule_demotes_is_never_promoted(): void
    {
        // The margin rule can demote a candidate that just verified, which is why promotion re-reads
        // the table instead of trusting the candidate in hand.
        $lead = $this->discoveredLead();
        $this->service()->recordCandidates($lead, [
            ['url' => 'https://vigandentalcare.ph', 'source' => 'search'],
            ['url' => 'https://vigandentalcare.com', 'source' => 'wikidata'],
        ], ['search', 'wikidata']);

        foreach (LeadWebsiteCandidate::query()->where('lead_id', $lead->id)->get() as $candidate) {
            $this->service()->recordVerification($candidate, $this->strongFindings($candidate->url));
        }

        $this->assertSame(
            0,
            LeadWebsiteCandidate::query()->where('lead_id', $lead->id)->where('verified', true)->count(),
        );
        $this->assertNull($lead->refresh()->website);
    }

    public function test_promotion_never_overwrites_a_website_somebody_else_supplied(): void
    {
        // A column that already holds an answer is a human's or an earlier ingest's decision.
        // Promotion fills a gap; it never settles a disagreement.
        $lead = $this->discoveredLead();
        $lead->update(['website' => 'https://entered-by-a-person.ph']);

        $this->service()->recordCandidates($lead, [
            ['url' => 'https://vigandentalcare.ph', 'source' => 'wikidata'],
        ], ['wikidata']);
        $candidate = LeadWebsiteCandidate::query()->where('lead_id', $lead->id)->firstOrFail();
        $this->service()->recordVerification($candidate, $this->strongFindings());

        $this->assertTrue($candidate->refresh()->verified);
        $this->assertSame('https://entered-by-a-person.ph', $lead->refresh()->website);
    }

    // --- routing: the whole point of the column ------------------------------

    public function test_a_promoted_lead_leaves_the_evidence_queue_and_joins_the_audit_queue(): void
    {
        // BEFORE: in the evidence queue, absent from the audit queue.
        // AFTER:  the reverse. That routing is what the seam broke and this restores.
        $this->actingAsNerbyService(); // the queues are the discovery service's (machine-only)
        $lead = $this->discoveredLead();

        $this->assertContains((string) $lead->id, $this->evidenceQueueIds());
        $this->assertNotContains((string) $lead->id, $this->auditQueueIds());

        $this->service()->recordCandidates($lead, [
            ['url' => 'https://vigandentalcare.ph', 'source' => 'wikidata'],
        ], ['wikidata']);
        $candidate = LeadWebsiteCandidate::query()->where('lead_id', $lead->id)->firstOrFail();
        $this->service()->recordVerification($candidate, $this->strongFindings());

        $this->assertNotContains((string) $lead->id, $this->evidenceQueueIds());
        $this->assertContains((string) $lead->id, $this->auditQueueIds());
    }

    public function test_a_lead_with_no_verified_candidate_stays_in_the_evidence_path(): void
    {
        $this->actingAsNerbyService(); // the queues are the discovery service's (machine-only)
        $lead = $this->discoveredLead();

        $this->service()->recordCandidates($lead, [
            ['url' => 'https://someone-else.ph', 'source' => 'search'],
        ], ['search']);
        $candidate = LeadWebsiteCandidate::query()->where('lead_id', $lead->id)->firstOrFail();
        $this->service()->recordVerification($candidate, $this->foreignFindings());

        $this->assertNull($lead->refresh()->website);
        $this->assertContains((string) $lead->id, $this->evidenceQueueIds());
        $this->assertNotContains((string) $lead->id, $this->auditQueueIds());
    }

    // --- the frozen boundaries, re-asserted against promotion ----------------

    public function test_promotion_does_not_write_website_status_or_move_priority(): void
    {
        // Promotion moves the lead to where a verdict gets MADE; it does not anticipate one. Only the
        // audit may change website_status, and only from real page findings.
        $lead = $this->discoveredLead();
        $this->assertSame('website-unknown', $lead->website_status);

        $this->service()->recordCandidates($lead, [
            ['url' => 'https://vigandentalcare.ph', 'source' => 'wikidata'],
        ], ['wikidata']);
        $candidate = LeadWebsiteCandidate::query()->where('lead_id', $lead->id)->firstOrFail();
        $this->service()->recordVerification($candidate, $this->strongFindings());

        $lead->refresh();
        $this->assertSame('website-unknown', $lead->website_status);
        $this->assertSame('medium', $this->nearbyPriorityFor($lead));
    }

    public function test_promotion_leaves_the_lead_score_untouched(): void
    {
        // `website` is deliberately absent from LeadObserver::SCORING_INPUTS, so this write cannot
        // move a score even on a QUALIFIED lead — which is the only kind that has one.
        $bd = $this->user('crm_bd');
        $lead = $this->discoveredLead($bd);
        $lead->update(['qualified_at' => now()]);
        $scoreBefore = $lead->refresh()->score;
        $this->assertNotNull($scoreBefore, 'a qualified lead should carry a score');

        $this->service()->recordCandidates($lead, [
            ['url' => 'https://vigandentalcare.ph', 'source' => 'wikidata'],
        ], ['wikidata']);
        $candidate = LeadWebsiteCandidate::query()->where('lead_id', $lead->id)->firstOrFail();
        $this->service()->recordVerification($candidate, $this->strongFindings());

        $lead->refresh();
        $this->assertSame('https://vigandentalcare.ph', $lead->website);
        $this->assertSame($scoreBefore, $lead->score);
    }

    public function test_absence_is_concluded_without_promoting_any_url(): void
    {
        // Promotion changes nothing about how absence is concluded: a search that verified nothing
        // promotes nothing, and the exhausted search reaches `no-website` on its own.
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->discoveredLead();

        $this->service()->recordCandidates($lead, [], WebsiteEvidenceService::REQUIRED_TIERS);
        $this->service()->markExhausted($lead);

        $lead->refresh();
        $this->assertSame('no-website', $lead->website_status);
        $this->assertSame('high', $this->nearbyPriorityFor($lead));
        // Absence is a conclusion about the business, not a URL. Nothing was promoted.
        $this->assertNull($lead->website);
    }

    /** @return list<string> lead ids the website-evidence queue currently offers */
    private function evidenceQueueIds(): array
    {
        return collect($this->getJson('/api/v1/crm/website-evidence/queue')->assertOk()->json('data'))
            ->pluck('id')->all();
    }

    /** @return list<string> lead ids the website-audit queue currently offers */
    private function auditQueueIds(): array
    {
        return collect($this->getJson('/api/v1/crm/website-enrichment/queue')->assertOk()->json('data'))
            ->pluck('id')->all();
    }

    public function test_a_promotion_is_withdrawn_when_the_margin_rule_later_refuses_it(): void
    {
        // FOUND BY TEST, NOT BY REASONING. Candidates verify ONE AT A TIME, so the first can be
        // promoted before a second arrives and applyMargin() refuses both. Without the withdrawal the
        // lead would keep — and be audited on — a URL its own evidence had just rejected.
        $lead = $this->discoveredLead();

        $this->service()->recordCandidates($lead, [
            ['url' => 'https://vigandentalcare.ph', 'source' => 'search'],
        ], ['search']);
        $first = LeadWebsiteCandidate::query()->where('lead_id', $lead->id)->firstOrFail();
        $this->service()->recordVerification($first, $this->strongFindings());

        // Promoted while it stood alone.
        $this->assertSame('https://vigandentalcare.ph', $lead->refresh()->website);

        // A second, equally strong candidate on another domain arrives.
        $this->service()->recordCandidates($lead, [
            ['url' => 'https://vigandentalcare.com', 'source' => 'wikidata'],
        ], ['wikidata']);
        $second = LeadWebsiteCandidate::query()
            ->where('lead_id', $lead->id)->where('url', 'https://vigandentalcare.com')->firstOrFail();
        $this->service()->recordVerification($second, $this->strongFindings($second->url));

        // Both refused for insufficient margin — so the promotion goes with them.
        $this->assertSame(
            0,
            LeadWebsiteCandidate::query()->where('lead_id', $lead->id)->where('verified', true)->count(),
        );
        $this->assertNull($lead->refresh()->website);
        // And the lead is back where the evidence chain can keep working on it.
        $this->assertSame('website-unknown', $lead->website_status);
    }

    public function test_the_withdrawal_only_touches_a_url_this_class_promoted(): void
    {
        // A human's URL matches no margin-refused candidate, so it survives an adjudication that
        // refuses every candidate on the lead.
        $lead = $this->discoveredLead();
        $lead->update(['website' => 'https://entered-by-a-person.ph']);

        $this->service()->recordCandidates($lead, [
            ['url' => 'https://vigandentalcare.ph', 'source' => 'search'],
            ['url' => 'https://vigandentalcare.com', 'source' => 'wikidata'],
        ], ['search', 'wikidata']);
        foreach (LeadWebsiteCandidate::query()->where('lead_id', $lead->id)->get() as $candidate) {
            $this->service()->recordVerification($candidate, $this->strongFindings($candidate->url));
        }

        $this->assertSame('https://entered-by-a-person.ph', $lead->refresh()->website);
    }

}
