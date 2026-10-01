<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\WebsiteIdentityVerifier;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A DERIVED DOMAIN IS NOT EVIDENCE ABOUT ITSELF.
 *
 * The probe tier spells a business name as a domain and keeps whatever resolves in DNS. For such a
 * candidate "the domain matches the name" is true BY CONSTRUCTION — the probe wrote that host from
 * that name — so counting it as a 40-point signal is counting the guess as proof of itself.
 *
 * Measured on the live pool before this fix: a bakery in Naga called "New York Bakery" produced the
 * probe candidate `newyork.com`, which scored 40 (domain, self-fulfilling) + 30 (title "NewYork.com",
 * equally self-fulfilling) = 70, clearing both the audit threshold of 50 and the evidence chain's
 * stricter 65. The lead would have been recorded as HAVING a website and dropped to Low priority —
 * the error `WebsiteEvidenceService` itself calls unrecoverable, because it silently removes a
 * genuine opportunity from the board.
 *
 * WHAT IS NOT CHANGED: no weight, no threshold, no `WebsiteStatusDeriver` branch, no priority rule.
 * Only which evidence is independent enough to be counted — the question the weights already assume
 * has been answered.
 */
class WebsiteProbeEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private WebsiteIdentityVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        User::factory()->create(['status' => 'active']);
        $this->verifier = new WebsiteIdentityVerifier();
    }

    private function lead(string $name, ?string $city = null, ?string $phone = null): Lead
    {
        return Lead::factory()->create([
            'assigned_to' => null,
            'business_name' => $name,
            'city' => $city,
            'phone' => $phone,
            'email' => null,
        ]);
    }

    /** @param array<string,mixed> $extra */
    private function verdict(Lead $lead, string $url, string $title, bool $derived, array $extra = [])
    {
        return $this->verifier->verify($lead, array_merge([
            'website_reachable' => true,
            'website_title' => $title,
            'website_description' => '',
            'final_url' => $url,
            'canonical_url' => null,
            'phones_found' => [],
            'emails_found' => [],
        ], $extra), $derived);
    }

    // ── P1: the probe false positives, every one from the live dry run ───────────────────────

    /**
     * Real leads, real probe candidates, real page titles.
     *
     * The third value is whether the derived host even reaches the domain check. Two of these never
     * do — "Julie's" and "Arbee's" shorten to five-letter tokens, below the length the matcher
     * requires of a single leading word — so they are refused one step earlier. Both routes end in
     * the same place and the test pins the outcome that matters: not accepted.
     */
    public static function probeFalsePositives(): array
    {
        return [
            'New York Bakery → newyork.com' => ['New York Bakery', 'https://newyork.com', 'NewYork.com', true],
            'Cake boss gensan → cakeboss.com' => ['Cake boss gensan', 'https://cakeboss.com', 'Cake Boss', true],
            "Julie's Bakeshop Fatima → julies.com" => ["Julie's Bakeshop Fatima", 'https://julies.com', "Julie's", false],
            "Arbee's Bakeshop San Carlos → arbees.com" => ["Arbee's Bakeshop San Carlos", 'https://arbees.com', "Arbee's", false],
            'Bascon Cakes San Carlos → basconcakes.com' => ['Bascon Cakes San Carlos', 'https://basconcakes.com', 'Bascon Cakes', true],
        ];
    }

    #[DataProvider('probeFalsePositives')]
    public function test_a_probe_candidate_cannot_be_accepted_on_its_own_derived_domain(
        string $business,
        string $url,
        string $title,
        bool $domainWouldMatch,
    ): void {
        $lead = $this->lead($business);

        $verdict = $this->verdict($lead, $url, $title, derived: true);

        // Where the domain does match, the signal is still RECORDED — the trail must show what was
        // examined — it simply carries no weight, so a title echoing the guessed domain cannot reach
        // the bar alone.
        $this->assertSame($domainWouldMatch, $verdict->signals['domain_matches_name']);
        $this->assertLessThan(
            WebsiteIdentityVerifier::THRESHOLD,
            $verdict->confidence,
            "{$business} must not be verified by a domain the probe itself wrote",
        );
        $this->assertFalse($verdict->verified);
    }

    public function test_the_same_candidate_would_still_have_passed_before_the_fix(): void
    {
        $lead = $this->lead('New York Bakery');

        // `derived: false` reproduces the old behaviour exactly — proof the regression was real and
        // that the fix is the ONLY thing standing between this lead and a wrong `has-website`.
        $before = $this->verdict($lead, 'https://newyork.com', 'NewYork.com', derived: false);

        $this->assertSame(70, $before->confidence);
        $this->assertTrue($before->verified);
    }

    public function test_a_probe_candidate_can_still_win_on_genuinely_independent_evidence(): void
    {
        $lead = $this->lead('Red Ribbon GenSan Highway', 'General Santos', '(083) 552 3344');

        // Domain discounted, but the page carries the branch's own phone number — evidence nothing
        // about the guess could manufacture. Probe candidates are not banned, only made to prove
        // something the probe did not already assume.
        $verdict = $this->verdict($lead, 'https://redribbon.com.ph', 'Red Ribbon Bakeshop', derived: true, extra: [
            'phones_found' => ['(083) 552 3344'],
        ]);

        $this->assertTrue($verdict->verified);
        $this->assertGreaterThanOrEqual(WebsiteIdentityVerifier::THRESHOLD, $verdict->confidence);
    }

    public function test_an_asserted_url_is_unaffected_by_the_probe_rule(): void
    {
        // Google asserted this URL; nobody derived it from the name. Its domain evidence is
        // independent and still counts, exactly as before.
        $lead = $this->lead('Eunice Bakery Cafe', 'Bacolod');

        $verdict = $this->verdict($lead, 'https://eunicebakery.cafe', 'Eunice Bakery Cafe — Bacolod', derived: false);

        $this->assertTrue($verdict->verified);
        $this->assertSame(80, $verdict->confidence);
    }

    // ── P3: a chain's corporate site titles itself after the brand, not the branch ────────────

    /** Real leads and the real titles their corporate sites return. */
    public static function chainBranches(): array
    {
        return [
            'Mercury Drug branch' => [
                'Mercury Drug Ayala Center The Residences', 'https://www.mercurydrug.com/', 'Mercury Drug Corporation',
            ],
            'Southstar Drug branch' => [
                'SOUTHSTAR DRUG MEDICAL TOWERS MAKATI', 'https://southstardrug.com.ph/', 'Southstar Drug: Online Drugstore & Delivery Philippines',
            ],
            'Wilcon Depot branch (subdomain)' => [
                'Wilcon Depot (San Rafael, Tarlac)', 'https://shop.wilcon.com.ph/', 'Wilcon Depot Inc.',
            ],
        ];
    }

    #[DataProvider('chainBranches')]
    public function test_a_chain_branch_is_recognised_by_its_corporate_site(
        string $business,
        string $url,
        string $title,
    ): void {
        $lead = $this->lead($business);

        // Measured before this fix: all three sat at exactly 40 — the domain matched, and then the
        // title check failed because a corporate page cannot name the branch. "Mercury Drug
        // Corporation" will never contain "Ayala Center The Residences".
        $verdict = $this->verdict($lead, $url, $title, derived: false);

        $this->assertTrue($verdict->verified, "{$business} should be recognised by its brand's site");
        $this->assertGreaterThanOrEqual(WebsiteIdentityVerifier::THRESHOLD, $verdict->confidence);
        $this->assertTrue($verdict->signals['title_matches_name']);
    }

    public function test_brand_matching_does_not_admit_an_unrelated_company(): void
    {
        // NARROWING, NEVER WIDENING. The brand path only reconsiders the TITLE once the domain has
        // already established which brand this is; a page about someone else still matches nothing.
        $lead = $this->lead('Casa Verde Bistro', 'Bacolod');

        $verdict = $this->verdict($lead, 'https://ratke.org', 'Ratke Industrial Supply', derived: false);

        $this->assertFalse($verdict->verified);
        $this->assertLessThan(WebsiteIdentityVerifier::THRESHOLD, $verdict->confidence);
    }

    public function test_a_platform_host_is_still_refused_outright(): void
    {
        // The earlier gate is untouched and still wins before any of this runs.
        $lead = $this->lead('Cafe Bobs', 'Bacolod', '+63 34 434 1234');

        $verdict = $this->verdict($lead, 'https://www.facebook.com/cafebobs/', 'Cafe Bobs | Facebook', derived: false, extra: [
            'phones_found' => ['+63 34 434 1234'],
        ]);

        $this->assertFalse($verdict->verified);
        $this->assertSame(0, $verdict->confidence);
        $this->assertTrue($verdict->signals['platform_hosted']);
    }
}
