<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadWebsiteCandidate;
use App\Modules\Crm\Models\LeadWebsiteSearch;
use App\Modules\Crm\Services\Discovery\GooglePlacesTextSearch;
use App\Modules\Crm\Services\Discovery\Prompts\NerbyDiscoveryInstruction;
use App\Modules\Crm\Services\Scoring\LeadScorer;
use App\Modules\Crm\Services\Website\LlmIdentityAdjudicator;
use App\Modules\Crm\Services\WebsiteIdentityVerdict;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\Concerns\ActsAsNerbyService;
use Tests\TestCase;

/**
 * STAGE 0 — the website-opportunity BASELINE. Characterisation only: every assertion here describes
 * what the system does TODAY, so a later stage has to demonstrate its change rather than assert it.
 *
 * NOTHING HERE IS AN ENDORSEMENT. Two of these tests were written to pin gaps that later stages
 * were expected to close, and say so where they still describe one.
 *
 * WHAT IS DELIBERATELY NOT DUPLICATED. The frozen point values, the +25 signal delta and the
 * zero-contribution of `website-unknown` are already pinned by {@see WebsiteStatusScoringTest}; the
 * social/directory/maps host rules by {@see WebsiteUrlClassificationTest}; the chain-brand verdict by
 * {@see WebsiteProbeEvidenceTest}; the advisory AI contract by {@see WebsiteIdentityAdjudicationTest}.
 * This file covers only what none of them reach: the PERSISTED score path, the priority column, the
 * named product rule end to end, the stranded-lead pipeline state, the fact that the env credential
 * is not a second way into the AI layer, and the discovery switches that must stay off.
 *
 * Deterministic by construction: no network, no Google Places, no OpenRouter, no DNS, no live page.
 */
class WebsiteOpportunityBaselineTest extends TestCase
{
    use ActsAsNerbyService;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        // LeadFactory stamps `created_by = 1`; that user has to exist for the FK to hold.
        User::factory()->create(['status' => 'active']);
        // No test here may reach the network. Any attempt is a failed assertion, not a slow test.
        Http::preventStrayRequests();
    }

    /**
     * An ACTIVE lead (`qualified_at` set, no discovery source) whose every other scoring input is
     * pinned, so a delta can only come from `website_status`.
     *
     * `last_activity` is recent ON PURPOSE. With it null the No Activity penalty fires and the total
     * clamps at `score_min`, which would silently absorb part of the swing under test and make the
     * delta read as something other than the configured weight.
     */
    private function activeLead(): Lead
    {
        return Lead::factory()->create([
            'business_name' => 'Fixture Co',
            'contact_name' => 'Fixture Co',   // the import fallback — NOT a real contact (no +5)
            'status' => 'new',
            'cold_calling' => 'not-called',
            'email_outreach' => 'none',
            'region' => null, 'province' => null, 'city' => null,
            'source' => null,
            'qualified_at' => now(),
            'last_activity' => now(),
            'website_status' => 'website-unknown',
            'priority' => 'medium',
        ]);
    }

    /** A discovered PROSPECT: has a provider source and has never been qualified into the CRM. */
    private function prospect(array $overrides = []): Lead
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

    private function submitAudit(Lead $lead, array $overrides = []): string
    {
        $this->actingAsNerbyService();

        return $this->putJson("/api/v1/crm/leads/{$lead->id}/website-enrichment", $this->findings($overrides))
            ->assertOk()
            ->json('websiteStatus');
    }

    // ── 1. the PERSISTED score path, through the observer ────────────────────────────────────

    /**
     * The delta the opportunity model depends on, measured where the product actually reads it:
     * `leads.score`, written by LeadObserver, not the calculator's return value.
     *
     * Why BOTH are worth pinning separately. WebsiteStatusScoringTest already proves the SIGNAL is
     * worth 25. This proves the signal survives the journey — observer wiring, the FIT cap, and the
     * final clamp — and arrives in the column. A change that broke only the wiring would leave that
     * test green and this one red, which is the distinction Stage 1 needs.
     */
    public function test_an_active_lead_gains_exactly_the_no_website_points_in_the_stored_score(): void
    {
        $lead = $this->activeLead();
        $before = (int) $lead->fresh()->score;

        $lead->update(['website_status' => 'no-website']);

        $after = (int) $lead->fresh()->score;

        $this->assertSame(25, $after - $before, 'no_website is +25 in config/scoring.php');
        $this->assertSame(
            $after,
            app(LeadScorer::class)->calculate($lead->fresh())->score,
            'the stored score must equal what the frozen calculator computes',
        );
    }

    /**
     * A PROSPECT IS NOT SCORED, and Website Intelligence writing to it changes nothing.
     *
     * This is easy to misread as a bug and is not one: a discovered business has no CRM score until a
     * BD qualifies it. It matters to the opportunity model because every lead the discovery pipeline
     * produces arrives in this state — so `no-website` on a prospect is a recorded FACT that is worth
     * nothing to the score until qualification, whatever the weights say.
     */
    public function test_a_prospect_is_not_rescored_when_website_intelligence_writes_its_status(): void
    {
        $lead = $this->prospect();
        $before = $lead->fresh()->score;

        $lead->update(['website_status' => 'no-website']);

        $this->assertTrue($lead->fresh()->isProspect());
        $this->assertSame($before, $lead->fresh()->score, 'an unqualified prospect must not be scored');
    }

    // ── 2. priority is a COLUMN, not a derivation ────────────────────────────────────────────

    /**
     * THERE IS NO SCORE-TO-PRIORITY MAPPING IN THE SCORING ENGINE, and this pins that.
     *
     * `leads.priority` is a stored column defaulting to `medium`, written on import. Nothing in
     * Services/Scoring reads or writes it. So "no-website raises Priority" is not a derivation the
     * code performs — the score moves, and Priority is a separate field. Any later stage that intends
     * `no-website` to change Priority has to add that link deliberately; it does not exist to inherit.
     */
    public function test_priority_is_not_derived_from_the_website_status_or_the_score(): void
    {
        $lead = $this->activeLead();
        $this->assertSame('medium', $lead->priority);

        $lead->update(['website_status' => 'no-website']);

        $this->assertSame('medium', $lead->fresh()->priority, 'scoring must not write leads.priority');
        $this->assertGreaterThan(0, (int) $lead->fresh()->score, 'the score, and only the score, moved');
    }

    // ── 3. the named product rule: a brand's official site counts ────────────────────────────

    /**
     * THE PO'S WORKED EXAMPLE, end to end and by name.
     *
     * Mercury Drug - Cainta with `mercurydrug.com` is HAS WEBSITE: a legitimate official website for
     * the brand counts, and this rule deliberately does NOT distinguish a corporate site from a
     * branch-specific one. The verdict is reached without the branch being named anywhere on the page
     * — no phone match, no city match — which is exactly the case the brand rule in
     * WebsiteIdentityVerifier exists to admit.
     *
     * Pinned end to end rather than at the verifier, because the product rule is about the STATUS a
     * BD sees, and the verdict is only one step of the three that produce it.
     */
    public function test_the_cainta_branch_is_has_website_on_the_brands_official_site(): void
    {
        $lead = $this->prospect([
            'business_name' => 'Mercury Drug - Cainta',
            'city' => 'Cainta',
            'phone' => null,
            'website' => 'https://www.mercurydrug.com',
        ]);

        $status = $this->submitAudit($lead, [
            'website_title' => 'Mercury Drug Corporation',
            'final_url' => 'https://www.mercurydrug.com/',
            'canonical_url' => 'https://www.mercurydrug.com/',
        ]);

        $this->assertSame('has-website', $status);

        $enrichment = $lead->fresh()->websiteEnrichment;
        $this->assertTrue($enrichment->identity_verified);
        // 40 (domain spells the brand) + 30 (title matches the brand, not the branch). Neither the
        // branch phone nor the city contributed — the corporate page carries neither.
        $this->assertSame(70, (int) $enrichment->identity_confidence);
    }

    // ── 4. the stranded lead — the Stage 1 target ────────────────────────────────────────────

    /**
     * ⚠️ PINS A LIMITATION THAT STAGE 1 DELIBERATELY DID NOT REMOVE.
     *
     * A lead holding a URL that FAILS identity verification is stuck. The audit owns it because
     * `website` is not null, and the evidence/tier path — the only route to `no-website` — selects on
     * `website IS NULL`, so it can never pick the lead up. Measured on the live pool when this was
     * written: 412 leads in that state.
     *
     * Stage 1 unsticks only the CONCLUSIVE ones — a URL refused on its landing host — via
     * {@see WebsiteRefusalRefilingTest}. THIS case is not conclusive: a reachable page the verifier
     * could not tie to the business means "we could not judge", and stripping a URL on that would
     * delete a genuine website on weak evidence. It was 340 of the 412, and it stays stranded on
     * purpose until something can actually read the page — the Stage 3/4 AI layer.
     *
     * So this test still passes after Stage 1, and that is the point: it now records the remaining
     * gap rather than the original one.
     */
    public function test_a_lead_whose_url_is_refused_is_stranded_outside_the_evidence_path(): void
    {
        $lead = $this->prospect(['website' => 'https://parked-domain-holding.example']);

        $status = $this->submitAudit($lead, [
            'website_title' => 'This domain is for sale',
            'final_url' => 'https://parked-domain-holding.example',
        ]);

        // Refused, and honestly so — unknown is not a claim that there is no website.
        $this->assertSame('website-unknown', $status);
        $this->assertFalse($lead->fresh()->websiteEnrichment->identity_verified);

        // The URL STAYS on the lead. This is the mechanism that strands it.
        $this->assertSame('https://parked-domain-holding.example', $lead->fresh()->website);

        $this->actingAsNerbyService();

        // Absent from the evidence queue, so no tier will ever search for its real site …
        $this->getJson('/api/v1/crm/website-evidence/queue')->assertOk()->assertJsonCount(0, 'data');

        // … and still offered to the audit queue, which has already said all it can say.
        $this->getJson('/api/v1/crm/website-enrichment/queue')->assertOk()
            ->assertJsonPath('data.0.id', (string) $lead->id)
            ->assertJsonPath('data.0.websiteStatus', 'website-unknown');

        // Nothing in the evidence chain exists for it, so `no-website` is unreachable by every path.
        $this->assertSame(0, LeadWebsiteSearch::query()->where('lead_id', $lead->id)->count());
        $this->assertSame(0, LeadWebsiteCandidate::query()->where('lead_id', $lead->id)->count());
    }

    // ── 5. the advisory AI layer's credential source — the Stage 3 target ────────────────────

    /**
     * THE ENV CREDENTIAL IS NO LONGER A WAY IN, and cannot become one again.
     *
     * This test was originally written to pin the opposite: the adjudicator gated on
     * `config('openai.*')` and never consulted the encrypted NERBY row, so funding OpenRouter did
     * not activate website adjudication. Stage 3 closed that gap — the audit now reads the one
     * encrypted `crm_nerby_provider_settings` credential through AiChatEndpoint.
     *
     * REWRITTEN RATHER THAN DELETED, because the gap it described has an obvious regression: a
     * future change that restores an env-based lane would give this deployment a SECOND AI
     * credential store, outside the encrypted settings the security review depends on. So the
     * assertion is inverted — a fully-populated env credential, with the audit switch off, still
     * reaches nothing. The env keys are inert here whatever they hold.
     */
    public function test_the_env_openai_credential_cannot_enable_the_website_audit(): void
    {
        // Everything the OLD gate needed, present and switched on.
        config([
            'openai.enabled' => true,
            'openai.api_key' => 'sk-env-'.str_repeat('a', 24),
            'openai.model' => 'gpt-4o-mini',
        ]);

        // The audit's own switch is the shipped default: off. No NERBY row exists either.
        $this->assertFalse((bool) config('discovery.website_audit.enabled'));

        $verdict = new WebsiteIdentityVerdict(verified: false, confidence: 40, signals: []);

        // Squarely inside the ambiguous band, so only the gate keeps the model unasked.
        // preventStrayRequests() would fail this test if any call were attempted.
        $this->assertSame(40, $verdict->confidence);
        $this->assertNull(
            app(LlmIdentityAdjudicator::class)->evidenceFor($this->prospect(), $this->findings(), $verdict),
            'the env credential must not be a second way into the AI layer',
        );
    }

    /**
     * The advisory guarantee is the RETURN TYPE, so it is pinned as a type rather than as behaviour:
     * a layer that cannot construct a verdict cannot promote one however it is called.
     */
    public function test_the_adjudicator_cannot_return_a_verdict(): void
    {
        $return = (new ReflectionMethod(LlmIdentityAdjudicator::class, 'evidenceFor'))->getReturnType();

        $this->assertSame('?array', (string) $return);
    }

    // ── 6. the discovery AI switches, which Stage 0 must leave untouched ─────────────────────

    /**
     * EVERY OPTIONAL AI CAPABILITY IS OFF, and off is what an environment that sets nothing gets.
     * Stage 2 turns these on deliberately; until then a green run here is the evidence that no test,
     * fixture or config edit has quietly opened a paid path.
     */
    public function test_every_discovery_ai_switch_is_off_by_default(): void
    {
        $this->assertFalse((bool) config('discovery.ai_strategy'));
        $this->assertFalse((bool) config('discovery.probe.enabled'));
        $this->assertFalse((bool) config('discovery.feedback.enabled'));
    }

    /**
     * The execution caps and the prompt version, pinned so Stage 2 cannot move them by accident.
     *
     * The caps are the BILLING fence: AI changes which phrases run, never how many. The version is
     * the cache key's leading component, so changing it silently invalidates every stored plan.
     */
    public function test_the_query_caps_and_prompt_version_are_unchanged(): void
    {
        $this->assertSame(3, GooglePlacesTextSearch::MAX_QUERIES);
        $this->assertSame(20, GooglePlacesTextSearch::PAGE_SIZE);
        $this->assertSame(3, GooglePlacesTextSearch::MAX_PAGES);
        $this->assertSame('2026-09-18.5', NerbyDiscoveryInstruction::VERSION);
    }
}
