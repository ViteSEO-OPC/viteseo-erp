<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadWebsiteCandidate;
use App\Modules\Crm\Services\Website\WebsiteEvidenceService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ActsAsNerbyService;
use Tests\TestCase;

/**
 * STAGE 1 — a CONCLUSIVELY refused website leaves the audit path and rejoins the evidence path.
 *
 * THE STATE THIS FIXES, and it is the exact mirror of the one
 * {@see WebsiteEvidenceService::promoteVerifiedWebsite()} already fixes from the other side.
 * `crm_leads.website` is what ROUTES a lead: the audit queue selects rows where it is set, the
 * evidence queue selects rows where it is null. A lead holding a URL the audit has conclusively
 * refused satisfied the wrong one — the audit kept offering it a page it had already judged, and the
 * tier search that could have found its real site could never see it. It sat at `website-unknown`
 * for good, which is Medium on the board and zero to the score.
 *
 * CONCLUSIVE IS A NARROW WORD HERE, DELIBERATELY. Only a refusal decided by the HOST qualifies: the
 * page landed on somebody else's platform, which is a fact about the domain rather than a judgement
 * about the evidence. Everything the verifier merely FAILED TO CONFIRM — an unmatched name, a thin
 * page, a weak signal — stays exactly where it is, because "we could not judge this" is not
 * "this is not a website", and refiling it would be asserting the difference away. That is the same
 * distinction {@see WebsiteEvidenceService::recordDeterminedAbsence()} already draws when it
 * withholds an absence from an inconclusively refused candidate.
 *
 * MEASURED ON THE LIVE POOL when this was written: 412 leads held a refused URL. Only 6 were refused
 * on the host; 340 were reachable pages the verifier could not confirm, and 66 never answered. This
 * change moves the 6. The other 406 are reported, not silently swept in — see the Stage 1 report.
 *
 * Deterministic: no network, no Google Places, no OpenRouter, no DNS, no live page.
 */
class WebsiteRefusalRefilingTest extends TestCase
{
    use ActsAsNerbyService;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        User::factory()->create(['status' => 'active']);
        Http::preventStrayRequests();
    }

    private function lead(array $overrides = []): Lead
    {
        return Lead::factory()->create([
            'business_name' => 'Cafe Bobs',
            'contact_name' => 'Cafe Bobs',
            'city' => 'Bacolod',
            'phone' => '+63 34 433 1234',
            'email' => null,
            'website' => 'https://cafebobs.ph',
            'website_status' => 'website-unknown',
            'source' => 'google_places',
            'place_id' => 'places/ChIJ'.fake()->unique()->numberBetween(1000, 99999),
            'qualified_at' => null,
            'assigned_to' => null,
            ...$overrides,
        ]);
    }

    /** A full, passing audit payload — override only what a test is about. */
    private function findings(array $overrides = []): array
    {
        return [
            'website_reachable' => true,
            'https_enabled' => true,
            'about_page' => true,
            'contact_page' => true,
            'services_page' => true,
            'emails_found' => [],
            'phones_found' => [],
            'provider' => 'scrapy-website-intelligence',
            ...$overrides,
        ];
    }

    private function audit(Lead $lead, array $overrides = []): string
    {
        $this->actingAsNerbyService();

        return $this->putJson("/api/v1/crm/leads/{$lead->id}/website-enrichment", $this->findings($overrides))
            ->assertOk()
            ->json('websiteStatus');
    }

    /** A domain that now forwards into a Facebook page — refused on the LANDING host. */
    private function redirectsIntoAPlatform(): array
    {
        return [
            'website_title' => 'Cafe Bobs',
            'final_url' => 'https://www.facebook.com/cafebobs',
            'canonical_url' => 'https://www.facebook.com/cafebobs',
            'phones_found' => ['+63 34 433 1234'],
        ];
    }

    /** A live page the verifier cannot tie to the business — INCONCLUSIVE, never refiled. */
    private function unconfirmable(): array
    {
        return [
            'website_title' => 'This domain is for sale',
            'final_url' => 'https://cafebobs.ph',
            'canonical_url' => 'https://cafebobs.ph',
        ];
    }

    private function candidates(Lead $lead)
    {
        return LeadWebsiteCandidate::query()->where('lead_id', $lead->id)->get();
    }

    // ── the conclusive path ──────────────────────────────────────────────────────────────────

    public function test_a_url_refused_on_its_landing_host_is_refiled_as_evidence(): void
    {
        $lead = $this->lead();

        $status = $this->audit($lead, $this->redirectsIntoAPlatform());

        // Refused, and honestly reported — removing the URL is not a claim that none exists.
        $this->assertSame('website-unknown', $status);
        $this->assertNull($lead->fresh()->website, 'a conclusively refused URL must not keep routing the lead');

        $candidate = $this->candidates($lead)->sole();
        $this->assertSame('https://cafebobs.ph', $candidate->url, 'the original URL is preserved verbatim');
        $this->assertSame(LeadWebsiteCandidate::SOURCE_AUDIT, $candidate->source);
        $this->assertSame(LeadWebsiteCandidate::REJECTED_PLATFORM, $candidate->rejection_reason);
        $this->assertFalse((bool) $candidate->verified);
        $this->assertNotNull($candidate->checked_at, 'it arrives already judged — nothing is left to re-check');
    }

    public function test_the_refiled_lead_swaps_queues(): void
    {
        $lead = $this->lead();
        $this->audit($lead, $this->redirectsIntoAPlatform());

        $this->actingAsNerbyService();

        // It has no URL now, so the tier search can finally look for its real site …
        $this->getJson('/api/v1/crm/website-evidence/queue')->assertOk()
            ->assertJsonPath('data.0.id', (string) $lead->id);

        // … and the audit, which has already said all it can, stops being offered it.
        $this->getJson('/api/v1/crm/website-enrichment/queue')->assertOk()->assertJsonCount(0, 'data');
    }

    /**
     * THE POINT OF THE WHOLE STAGE: the lead can now reach a verified absence, which it could not
     * before. The platform refusal does not block the determination, because it is not one of the
     * two inconclusive reasons — we know exactly what that URL was.
     */
    public function test_a_refiled_lead_can_reach_no_website_once_the_tiers_are_exhausted(): void
    {
        $lead = $this->lead();
        $this->audit($lead, $this->redirectsIntoAPlatform());

        // The refusal is on record and it is NOT one of the two inconclusive reasons, so the
        // determination proceeds over it rather than being withheld.
        $this->assertSame(
            LeadWebsiteCandidate::REJECTED_PLATFORM,
            $this->candidates($lead)->sole()->rejection_reason,
        );

        $service = app(WebsiteEvidenceService::class);
        $service->recordCandidates($lead->fresh(), [], WebsiteEvidenceService::REQUIRED_TIERS);
        $service->markExhausted($lead->fresh());

        $this->assertSame('no-website', $lead->fresh()->website_status);
    }

    /** The existing derived board priority picks it up with no priority rule changing. */
    public function test_the_refiled_lead_finally_renders_high_on_the_nearby_board(): void
    {
        $bd = User::factory()->create(['status' => 'active']);
        $bd->assignRole('crm_bd');

        $lead = $this->lead(['assigned_to' => $bd->id]);
        $this->audit($lead, $this->redirectsIntoAPlatform());

        $this->assertNull($lead->fresh()->website, 'the lead only reaches the tiers once the URL is refiled');

        $service = app(WebsiteEvidenceService::class);
        $service->recordCandidates($lead->fresh(), [], WebsiteEvidenceService::REQUIRED_TIERS);
        $service->markExhausted($lead->fresh());

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($bd);

        $row = collect($this->getJson('/api/v1/crm/nearby-clients')->assertOk()->json('data'))
            ->firstWhere('id', (string) $lead->id);

        $this->assertSame('high', $row['priority']);
        $this->assertSame('no-website', $row['website']['status']);
    }

    public function test_refiling_the_same_lead_twice_does_not_duplicate_the_evidence(): void
    {
        $lead = $this->lead();

        $this->audit($lead, $this->redirectsIntoAPlatform());
        // The second pass sees no URL at all, so there is nothing left to refile.
        $this->audit($lead->fresh(), $this->redirectsIntoAPlatform());

        $this->assertCount(1, $this->candidates($lead));
    }

    // ── everything inconclusive stays exactly where it is ────────────────────────────────────

    /**
     * ⚠️ THE 340. A reachable page the verifier could not tie to the business is NOT refiled, and the
     * lead stays stranded. That is deliberate under the Stage 1 rule: "we could not judge" is not a
     * refusal, and treating it as one would let a genuine website be stripped on weak evidence.
     * Resolving these needs something that can read the page — the Stage 3/4 AI layer.
     */
    public function test_a_page_that_merely_could_not_be_confirmed_is_left_alone(): void
    {
        $lead = $this->lead();

        $status = $this->audit($lead, $this->unconfirmable());

        $this->assertSame('website-unknown', $status);
        $this->assertSame('https://cafebobs.ph', $lead->fresh()->website, 'an unconfirmed URL is not a refused one');
        $this->assertCount(0, $this->candidates($lead));
    }

    /**
     * ⚠️ THE 66. A URL that did not answer is not refiled either. A site can be down for a day, and
     * `website-unreachable` already says exactly that without asserting an absence. Whether a
     * persistently dead URL should become a refusal is an open product question, not a Stage 1 one.
     */
    public function test_an_unreachable_url_is_not_refiled(): void
    {
        $lead = $this->lead();

        $status = $this->audit($lead, [
            'website_reachable' => false,
            'final_url' => 'https://cafebobs.ph',
        ]);

        $this->assertSame('website-unreachable', $status);
        $this->assertSame('https://cafebobs.ph', $lead->fresh()->website);
        $this->assertCount(0, $this->candidates($lead));
    }

    /** A verified site is never touched — refiling reaches only refusals. */
    public function test_a_verified_website_is_never_refiled(): void
    {
        $lead = $this->lead();

        $status = $this->audit($lead, [
            'website_title' => 'Cafe Bobs — Bacolod',
            'final_url' => 'https://cafebobs.ph',
            'canonical_url' => 'https://cafebobs.ph',
            'phones_found' => ['+63 34 433 1234'],
        ]);

        $this->assertSame('has-website', $status);
        $this->assertSame('https://cafebobs.ph', $lead->fresh()->website);
        $this->assertCount(0, $this->candidates($lead));
    }

    /**
     * THE FROZEN PRODUCT RULE, re-asserted at the boundary this stage changes. A brand's official
     * site counts, and the branch keeps it: the verdict is verified, so refiling never runs.
     */
    public function test_the_cainta_branch_keeps_its_brands_official_site(): void
    {
        $lead = $this->lead([
            'business_name' => 'Mercury Drug - Cainta',
            'city' => 'Cainta',
            'phone' => null,
            'website' => 'https://www.mercurydrug.com',
        ]);

        $status = $this->audit($lead, [
            'website_title' => 'Mercury Drug Corporation',
            'final_url' => 'https://www.mercurydrug.com/',
            'canonical_url' => 'https://www.mercurydrug.com/',
        ]);

        $this->assertSame('has-website', $status);
        $this->assertSame('https://www.mercurydrug.com', $lead->fresh()->website);
        $this->assertCount(0, $this->candidates($lead));
    }

    // ── what refiling must not disturb ───────────────────────────────────────────────────────

    /**
     * `website` is deliberately absent from LeadObserver's scoring inputs, so moving a URL cannot
     * move a score — asserted on a QUALIFIED lead, where a score exists to be moved at all.
     */
    public function test_refiling_moves_no_score_and_no_priority(): void
    {
        $lead = $this->lead(['source' => null, 'qualified_at' => now(), 'last_activity' => now()]);
        $scoreBefore = (int) $lead->fresh()->score;

        $this->audit($lead, $this->redirectsIntoAPlatform());

        $this->assertSame($scoreBefore, (int) $lead->fresh()->score);
        $this->assertSame($lead->priority, $lead->fresh()->priority);
    }

    /** The audit record itself is unchanged by refiling — the trail still says what was examined. */
    public function test_the_audit_record_still_holds_the_refused_verdict(): void
    {
        $lead = $this->lead();
        $this->audit($lead, $this->redirectsIntoAPlatform());

        $enrichment = $lead->fresh()->websiteEnrichment;
        $this->assertFalse((bool) $enrichment->identity_verified);
        $this->assertTrue((bool) ($enrichment->identity_signals['platform_hosted'] ?? false));
    }
}
