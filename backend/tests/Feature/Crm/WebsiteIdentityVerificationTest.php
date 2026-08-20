<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\WebsiteIdentityVerifier;
use App\Modules\Crm\Services\WebsiteStatusDeriver;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Website Intelligence — the five-state website model and the identity gate in front of it.
 *
 * The defect these pin: discovery set `website_status` from whether a directory record happened to
 * carry a URL tag, and the audit set it from whether a server answered. Neither is evidence about the
 * BUSINESS. A Mercury Drug branch with no OSM `website` tag was stored as `no-website`, which the UI
 * rendered as "No website was found for this business" and the Lead Score rewarded with +25 — the
 * largest Fit signal in the spec — for a company with an obvious web presence.
 *
 * The rule now: nothing claims a website state until a page has been fetched AND tied back to the
 * business. Everything else is `website-unknown`, and `website-unknown` scores zero.
 */
class WebsiteIdentityVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        User::factory()->create(['status' => 'active']);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('crm_admin');

        return $user;
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

    private function submit(Lead $lead, array $overrides = []): string
    {
        Sanctum::actingAs($this->admin());

        return $this->putJson("/api/v1/crm/leads/{$lead->id}/website-enrichment", $this->findings($overrides))
            ->assertOk()
            ->json('websiteStatus');
    }

    // --- Step 1: the state model ---------------------------------------------

    /**
     * The baseline state. `Lead::$attributes` (the in-memory default) and the `crm_leads` column
     * default must agree, because the two apply on different paths — Eloquent does not read DB
     * defaults back into the model, and a mismatch would have the score engine and the stored row
     * disagreeing about a lead nobody has checked. LeadFactory always sets an explicit status, so it
     * is bypassed here on purpose.
     */
    public function test_a_lead_starts_unknown_rather_than_claiming_no_website(): void
    {
        $this->assertSame('website-unknown', (new Lead)->website_status, 'model default');

        $lead = Lead::query()->create([
            'business_name' => 'Unset Co',
            'contact_name' => 'Unset Co',
            'created_by' => User::query()->value('id'),
        ]);

        $this->assertSame('website-unknown', $lead->fresh()->website_status, 'database default');
    }

    /**
     * The Mercury Drug case, end to end: a discovered branch with no URL in the provider record.
     * Before this change it was stored as `no-website` (+25, "No website was found"); now it is
     * unknown, scores nothing, and stays that way because there is no URL to audit.
     */
    public function test_a_discovered_business_with_no_provider_url_is_unknown_not_no_website(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/crm/leads/discovered', [
            'dry_run' => false,
            'businesses' => [[
                'business_name' => 'Mercury Drug Vigan',
                'industry' => 'pharmacy',
                'source' => 'openstreetmap',
                'place_id' => 'osm-node-mercury-1',
                'city' => 'Vigan',
            ]],
        ])->assertSuccessful();

        $lead = Lead::query()->where('place_id', 'osm-node-mercury-1')->firstOrFail();

        $this->assertNull($lead->website);
        $this->assertSame('website-unknown', $lead->website_status);
    }

    /**
     * Discovery cannot verify, so it cannot claim — whatever status it reports is overwritten. The
     * field stays ACCEPTED (not rejected) so an older deployed scraper keeps ingesting: the CRM and
     * the scraper release independently.
     */
    public function test_the_ingest_refuses_a_website_claim_from_the_provider(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/crm/leads/discovered', [
            'dry_run' => false,
            'businesses' => [[
                'business_name' => 'Legacy Scraper Co',
                'source' => 'openstreetmap',
                'place_id' => 'osm-node-legacy-1',
                'website' => 'https://legacyscraper.example',
                'website_status' => 'has-website', // what the CURRENTLY DEPLOYED scraper sends
            ]],
        ])->assertSuccessful();

        $lead = Lead::query()->where('place_id', 'osm-node-legacy-1')->firstOrFail();

        $this->assertSame('https://legacyscraper.example', $lead->website, 'the URL is still captured');
        $this->assertSame('website-unknown', $lead->website_status, 'but the claim is not');
    }

    public function test_an_unreachable_url_is_unreachable_not_no_website(): void
    {
        $lead = Lead::factory()->create(['website' => 'https://dead.example']);

        $this->assertSame('website-unreachable', $this->submit($lead, [
            'website_reachable' => false,
            'https_enabled' => false,
            'about_page' => false,
            'contact_page' => false,
            'services_page' => false,
        ]));
    }

    /** `no-website` now means "verified absent" and no audit can produce it. Only Step 3 or a human. */
    public function test_the_audit_can_never_derive_no_website(): void
    {
        $deriver = new WebsiteStatusDeriver;

        foreach ([true, false] as $reachable) {
            foreach ([true, false] as $verified) {
                foreach ([true, false] as $https) {
                    $status = $deriver->derive(
                        hasUrl: true, reachable: $reachable, identityVerified: $verified,
                        https: $https, aboutPage: $https, contactPage: $https, servicesPage: $https,
                    );
                    $this->assertNotSame('no-website', $status);
                }
            }
        }
    }

    // --- Step 2: identity verification ---------------------------------------

    /** A reachable page alone is not enough — the explicit requirement. */
    public function test_a_reachable_but_unidentified_page_stays_unknown(): void
    {
        $lead = Lead::factory()->create([
            'business_name' => 'Vigan Dental Care',
            'website' => 'https://parked-domain-holding.example',
            'phone' => null,
            'email' => null,
            'city' => null,
        ]);

        $this->assertSame('website-unknown', $this->submit($lead, [
            'website_title' => 'This domain is for sale',
            'final_url' => 'https://parked-domain-holding.example',
        ]));
    }

    public function test_a_verified_page_with_full_structure_is_has_website(): void
    {
        $lead = Lead::factory()->create([
            'business_name' => 'Mercury Drug',
            'website' => 'https://mercurydrug.com.ph',
        ]);

        $status = $this->submit($lead, [
            'website_title' => 'Mercury Drug | Trusted Since 1945',
            'final_url' => 'https://www.mercurydrug.com.ph/',
            'canonical_url' => 'https://www.mercurydrug.com.ph/',
        ]);

        $this->assertSame('has-website', $status);
        $this->assertTrue($lead->fresh()->websiteEnrichment->identity_verified);
    }

    public function test_a_verified_page_missing_core_pages_is_needs_redesign(): void
    {
        $lead = Lead::factory()->create([
            'business_name' => 'Mercury Drug',
            'website' => 'https://mercurydrug.com.ph',
        ]);

        $this->assertSame('needs-redesign', $this->submit($lead, [
            'website_title' => 'Mercury Drug',
            'about_page' => false,
            'final_url' => 'https://mercurydrug.com.ph',
        ]));
    }

    /**
     * Identity is judged where the request LANDED, not where it was aimed. A redirect into an
     * unrelated host must not be recorded as the business's own website.
     */
    public function test_a_redirect_to_an_unrelated_host_is_not_accepted(): void
    {
        $lead = Lead::factory()->create([
            'business_name' => 'Vigan Dental Care',
            'website' => 'https://vigandentalcare.ph',
            'phone' => null, 'email' => null, 'city' => null,
        ]);

        $status = $this->submit($lead, [
            'website_title' => 'Buy this domain',
            'final_url' => 'https://sedoparking.example/vigandentalcare',
            'canonical_url' => 'https://sedoparking.example/',
        ]);

        $this->assertSame('website-unknown', $status);
        $this->assertTrue($lead->fresh()->websiteEnrichment->identity_signals['redirected_off_domain']);
    }

    /** Multiple branches legitimately share one corporate domain — each verifies independently. */
    public function test_two_branches_of_one_chain_both_verify_against_the_same_corporate_site(): void
    {
        $findings = [
            'website_title' => 'Mercury Drug | Trusted Since 1945',
            'final_url' => 'https://www.mercurydrug.com.ph/',
            'canonical_url' => 'https://www.mercurydrug.com.ph/',
        ];

        foreach (['Mercury Drug Vigan', 'Mercury Drug Bantay'] as $name) {
            $lead = Lead::factory()->create([
                'business_name' => $name,
                'website' => 'https://mercurydrug.com.ph',
            ]);

            $this->assertSame('has-website', $this->submit($lead, $findings), "{$name} should verify");
        }

        $this->assertSame(2, Lead::query()->where('business_name', 'like', 'Mercury Drug%')->count(),
            'branch-level prospects stay separate — no company-level dedup was introduced');
    }

    // --- The verifier in isolation -------------------------------------------

    public function test_a_matching_phone_corroborates_a_matching_title(): void
    {
        $lead = Lead::factory()->make([
            'business_name' => 'Aling Nena Bakeshop',
            'website' => 'https://some-host.example',
            'phone' => '+63 917 555 1234',
        ]);

        $verdict = app(WebsiteIdentityVerifier::class)->verify($lead, $this->findings([
            'website_title' => 'Aling Nena Bakeshop',
            'phones_found' => ['(0917) 555-1234'],
            'final_url' => 'https://some-host.example',
        ]));

        $this->assertTrue($verdict->verified);
        $this->assertTrue($verdict->signals['phone_match'], 'the +63 / 0 prefix pair must compare equal');
        $this->assertTrue($verdict->signals['title_matches_name']);
    }

    /** A weak signal must never carry a verdict on its own. */
    public function test_a_city_mention_alone_is_not_enough(): void
    {
        $lead = Lead::factory()->make([
            'business_name' => 'Northside Auto Repair',
            'website' => 'https://unrelated.example',
            'city' => 'Vigan', 'phone' => null, 'email' => null,
        ]);

        $verdict = app(WebsiteIdentityVerifier::class)->verify($lead, $this->findings([
            'website_title' => 'Vigan City Tourism Portal',
            'final_url' => 'https://unrelated.example',
        ]));

        $this->assertFalse($verdict->verified);
        $this->assertTrue($verdict->signals['city_match']);
        $this->assertLessThan(WebsiteIdentityVerifier::THRESHOLD, $verdict->confidence);
    }

    public function test_an_unreachable_page_is_never_evaluated_for_identity(): void
    {
        $lead = Lead::factory()->make(['business_name' => 'Anything', 'website' => 'https://x.example']);

        $verdict = app(WebsiteIdentityVerifier::class)->verify($lead, $this->findings([
            'website_reachable' => false,
        ]));

        $this->assertFalse($verdict->verified);
        $this->assertSame(0, $verdict->confidence);
        $this->assertSame([], $verdict->signals, 'no page means no evidence, not failed evidence');
    }
}
