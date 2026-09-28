<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\LeadImportService;
use App\Modules\Crm\Services\Website\WebsiteUrlClassifier;
use App\Modules\Crm\Services\WebsiteIdentityVerifier;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A URL RETURNED BY GOOGLE IS NOT AUTOMATICALLY A WEBSITE.
 *
 * Google Places returns one `websiteUri` per place and it is whatever the owner typed there. Measured
 * on the live pool at the time of writing: of 237 discovered URLs, 79 (33%) were social profiles —
 * 72 Facebook, 7 Instagram — every one stored in `crm_leads.website` and shown to BDs under WEBSITE.
 *
 * WHY THIS IS A SCORING PROBLEM AND NOT ONLY A DISPLAY ONE. The audit would have fetched those pages
 * and asked {@see WebsiteIdentityVerifier} whether they belong to the business — and for a Facebook
 * page the honest answer is yes. Its title carries the business name (30) and the page lists the
 * shop's phone (35): 65 against a threshold of 50. Verified, the status deriver would have moved the
 * lead to `needs-redesign` or `has-website`, and those are frozen Lead Score inputs. A business whose
 * only web presence is a Facebook page is the PROSPECT this CRM exists to find; recording it as
 * having a website inverts the signal it was discovered for.
 *
 * The fix classifies by HOST — checkable without fetching anything — so it can run at the ingest door
 * and again above the verifier's signal weights. Every example below is real data from the pool.
 */
class WebsiteUrlClassificationTest extends TestCase
{
    use RefreshDatabase;

    private WebsiteUrlClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        // The Lead factory stamps `created_by = 1`; that user has to exist for the FK to hold.
        User::factory()->create(['status' => 'active']);
        $this->classifier = new WebsiteUrlClassifier();
    }

    // ── A. social URLs are social, never websites ────────────────────────────────────────────

    /** Real URLs from the pool, plus the host variants Facebook actually serves. */
    public static function socialUrls(): array
    {
        return [
            'facebook (real: Cafe Bobs)' => ['https://www.facebook.com/cafebobs/', 'facebook'],
            'facebook web. subdomain' => ['https://web.facebook.com/tarakantotayo', 'facebook'],
            'facebook m. subdomain' => ['https://m.facebook.com/BasconCakes', 'facebook'],
            'facebook no scheme' => ['facebook.com/DonJuneFoodHouse', 'facebook'],
            'facebook with tracking' => ['https://www.facebook.com/alaiacoffee?mibextid=LQQJ4d', 'facebook'],
            'fb.com short' => ['https://fb.com/luckychantarlac', 'facebook'],
            'instagram (real: Buttery Bacolod)' => ['https://www.instagram.com/butterynegros/', 'instagram'],
            'tiktok' => ['https://www.tiktok.com/@somecafe', null],
            'linkedin' => ['https://www.linkedin.com/company/some-co', null],
            'youtube' => ['https://www.youtube.com/@somechannel', null],
            'x/twitter' => ['https://x.com/somebiz', null],
        ];
    }

    #[DataProvider('socialUrls')]
    public function test_a_social_url_is_classified_social_and_never_an_own_website(string $url, ?string $column): void
    {
        $this->assertSame(WebsiteUrlClassifier::KIND_SOCIAL, $this->classifier->classify($url), $url);
        $this->assertFalse($this->classifier->isOwnWebsiteCandidate($url), $url);
        $this->assertSame($column, $this->classifier->socialColumn($url), $url);
    }

    public function test_a_discovered_facebook_url_is_filed_as_facebook_and_leaves_website_empty(): void
    {
        $this->ingest([$this->business('Cafe Bobs Flagship', 'https://www.facebook.com/cafebobs/')]);

        $lead = Lead::query()->where('business_name', 'Cafe Bobs Flagship')->firstOrFail();

        // MOVED, NOT DROPPED. The Lead has had a `facebook` column all along; this is where a
        // Facebook page belongs.
        $this->assertSame('https://www.facebook.com/cafebobs/', $lead->facebook);
        $this->assertNull($lead->website, 'a social profile must never be stored as the website');
        $this->assertSame('website-unknown', $lead->website_status);
    }

    public function test_a_discovered_instagram_url_is_filed_as_instagram(): void
    {
        $this->ingest([$this->business('Buttery Bacolod', 'https://www.instagram.com/butterynegros/')]);

        $lead = Lead::query()->where('business_name', 'Buttery Bacolod')->firstOrFail();

        $this->assertSame('https://www.instagram.com/butterynegros/', $lead->instagram);
        $this->assertNull($lead->website);
    }

    // ── C. directories and link hubs are not the business's site ─────────────────────────────

    /** Also real: Louise Cakes → tripadvisor, Bascon Cakes → linktr.ee, a condo listing → bluepillow. */
    public static function notOwnUrls(): array
    {
        return [
            'tripadvisor listing' => ['https://www.tripadvisor.com.ph/Restaurant_Review-g298573.html', WebsiteUrlClassifier::KIND_DIRECTORY],
            'linktr.ee hub' => ['https://linktr.ee/basconcakes', WebsiteUrlClassifier::KIND_LINK_IN_BIO],
            'bluepillow aggregator' => ['https://www.bluepillow.com/search/manila', WebsiteUrlClassifier::KIND_DIRECTORY],
            'google business site' => ['https://somebakery.business.site/', WebsiteUrlClassifier::KIND_DIRECTORY],
            'google maps short link' => ['https://maps.app.goo.gl/abc123', WebsiteUrlClassifier::KIND_DIRECTORY],
            'foodpanda listing' => ['https://www.foodpanda.ph/restaurant/abcd', WebsiteUrlClassifier::KIND_DIRECTORY],
            'shopee store' => ['https://shopee.ph/someshop', WebsiteUrlClassifier::KIND_DIRECTORY],
        ];
    }

    #[DataProvider('notOwnUrls')]
    public function test_a_directory_or_link_hub_is_not_an_own_website(string $url, string $kind): void
    {
        $this->assertSame($kind, $this->classifier->classify($url), $url);
        $this->assertFalse($this->classifier->isOwnWebsiteCandidate($url), $url);
    }

    public function test_a_directory_listing_is_not_attached_as_the_leads_website(): void
    {
        $this->ingest([$this->business('Louise Cakes and Pastries', 'https://www.tripadvisor.com.ph/Restaurant_Review-g1.html')]);

        $lead = Lead::query()->where('business_name', 'Louise Cakes and Pastries')->firstOrFail();

        // The BUSINESS is preserved in full — only the claim that this URL is its website is refused.
        $this->assertNull($lead->website);
        $this->assertSame('website-unknown', $lead->website_status, 'refusing a URL must not assert "no website"');
        $this->assertNotNull($lead->place_id);
        $this->assertSame(10.6407, (float) $lead->latitude);
    }

    // ── B/E. a real own domain still goes through, and still has to be verified ──────────────

    /** Real domains from the pool that ARE the business's own site. */
    public static function ownUrls(): array
    {
        return [
            'eunicebakery.cafe' => ['https://eunicebakery.cafe'],
            'mercurydrug.com (chain)' => ['https://www.mercurydrug.com/'],
            'allaboutbaking.ph' => ['https://www.allaboutbaking.ph'],
            'manilamanorhotel.shop' => ['https://manilamanorhotel.shop'],
            'bare host, no scheme' => ['southstardrug.com.ph'],
            'a domain that merely contains a platform name' => ['https://myfacebook-cakes.ph'],
            'a .ph domain ending in a platform word' => ['https://notfacebook.com.ph'],
        ];
    }

    #[DataProvider('ownUrls')]
    public function test_a_plausible_business_domain_is_still_treated_as_a_candidate_website(string $url): void
    {
        // UNRECOGNISED MEANS CANDIDATE, NOT OWNED — the identity verifier still decides. The last two
        // cases pin the host-suffix boundary: a substring test would seize both of these domains.
        $this->assertSame(WebsiteUrlClassifier::KIND_OWN, $this->classifier->classify($url), $url);
        $this->assertTrue($this->classifier->isOwnWebsiteCandidate($url), $url);
    }

    public function test_a_real_business_domain_is_ingested_as_the_website(): void
    {
        $this->ingest([$this->business('Eunice Bakery Cafe', 'https://eunicebakery.cafe')]);

        $lead = Lead::query()->where('business_name', 'Eunice Bakery Cafe')->firstOrFail();

        $this->assertSame('https://eunicebakery.cafe', $lead->website);
        $this->assertNull($lead->facebook);
        // Still unproven: discovery observes a URL, it never confirms ownership.
        $this->assertSame('website-unknown', $lead->website_status);
    }

    public function test_an_invalid_url_is_neither_a_website_nor_a_social_link(): void
    {
        // `mailto:` and a bare email are the traps: prefixing a scheme would parse either into
        // credentials plus a host, inventing a domain the record never carried.
        foreach (['', '   ', 'not-a-url', 'mailto:hi@example.com', 'hi@example.com', 'tel:+63341234'] as $bad) {
            $this->assertSame(WebsiteUrlClassifier::KIND_INVALID, $this->classifier->classify($bad), $bad);
            $this->assertNull($this->classifier->socialColumn($bad), $bad);
        }
    }

    // ── D. the scoring input: verification is what gates it ──────────────────────────────────

    public function test_a_facebook_page_can_never_pass_identity_verification(): void
    {
        $lead = Lead::factory()->create([
            'assigned_to' => null,
            'business_name' => 'Cafe Bobs',
            'website' => 'https://www.facebook.com/cafebobs/',
            'phone' => '+63 34 434 1234',
            'city' => 'Bacolod',
        ]);

        // A page that matches on EVERY content signal — name in the title, the shop's phone, its
        // city. On weights alone this is 30 + 35 + 10 = 75, comfortably past the threshold of 50.
        $verdict = (new WebsiteIdentityVerifier())->verify($lead, [
            'website_reachable' => true,
            'website_title' => 'Cafe Bobs | Facebook',
            'website_description' => 'Cafe Bobs, Bacolod City',
            'final_url' => 'https://www.facebook.com/cafebobs/',
            'phones_found' => ['+63 34 434 1234'],
        ]);

        $this->assertFalse($verdict->verified, 'a Facebook page is not the business\'s own website');
        $this->assertSame(0, $verdict->confidence);
        $this->assertTrue($verdict->signals['platform_hosted']);
    }

    public function test_a_redirect_into_a_platform_is_caught_at_the_landing_host(): void
    {
        $lead = Lead::factory()->create([
            'assigned_to' => null,
            'business_name' => 'Alaia Coffee',
            'website' => 'https://alaiacoffee.ph',
            'city' => 'Tarlac',
        ]);

        // The domain still resolves but now forwards to Facebook — a business that no longer has its
        // own site. Judged on where it LANDED, exactly as redirects were always judged.
        $verdict = (new WebsiteIdentityVerifier())->verify($lead, [
            'website_reachable' => true,
            'website_title' => 'Alaia Coffee | Facebook',
            'final_url' => 'https://www.facebook.com/alaiacoffee',
            'canonical_url' => 'https://www.facebook.com/alaiacoffee',
        ]);

        $this->assertFalse($verdict->verified);
        $this->assertTrue($verdict->signals['platform_hosted']);
    }

    public function test_a_genuine_business_site_still_verifies_exactly_as_before(): void
    {
        $lead = Lead::factory()->create([
            'assigned_to' => null,
            'business_name' => 'Eunice Bakery Cafe',
            'website' => 'https://eunicebakery.cafe',
            'city' => 'Bacolod',
        ]);

        // THE REGRESSION GUARD. The gate must refuse platforms without making real sites harder to
        // confirm: domain (40) + title (30) is the same 70 it always was.
        $verdict = (new WebsiteIdentityVerifier())->verify($lead, [
            'website_reachable' => true,
            'website_title' => 'Eunice Bakery Cafe — freshly baked in Bacolod',
            'final_url' => 'https://eunicebakery.cafe/',
            'canonical_url' => 'https://eunicebakery.cafe/',
        ]);

        $this->assertTrue($verdict->verified);
        $this->assertGreaterThanOrEqual(WebsiteIdentityVerifier::THRESHOLD, $verdict->confidence);
        $this->assertArrayNotHasKey('platform_hosted', $verdict->signals);
    }

    public function test_an_unrelated_domain_is_left_unverified_rather_than_attached(): void
    {
        $lead = Lead::factory()->create([
            'assigned_to' => null,
            'business_name' => 'Casa Verde Bistro',
            'website' => 'https://ratke.org',
            'city' => 'Bacolod',
        ]);

        // Reachable, but nothing ties the page to this business. Unconfirmed — never "no website",
        // which would award the greenfield signal on no evidence.
        $verdict = (new WebsiteIdentityVerifier())->verify($lead, [
            'website_reachable' => true,
            'website_title' => 'Ratke Industrial Supply',
            'final_url' => 'https://ratke.org/',
        ]);

        $this->assertFalse($verdict->verified);
        $this->assertLessThan(WebsiteIdentityVerifier::THRESHOLD, $verdict->confidence);
    }

    // ── presentation: the board never shows a profile under WEBSITE ──────────────────────────

    public function test_the_board_reports_a_social_profile_as_social_not_as_a_website(): void
    {
        // A row as it exists TODAY, ingested before the classifier — the URL is still in `website`.
        Lead::factory()->create([
            'assigned_to' => null,
            'business_name' => 'Bascon Cakes 888',
            'source' => 'google_places',
            'website' => 'http://www.facebook.com/BasconCakes',
            'latitude' => 10.6407,
            'longitude' => 122.9689,
        ]);

        $bd = User::factory()->create(['status' => 'active']);
        $bd->assignRole('crm_bd');
        Sanctum::actingAs($bd);

        $row = collect($this->getJson('/api/v1/crm/nearby-clients')->assertOk()->json('data'))
            ->firstWhere('business', 'Bascon Cakes 888');

        $this->assertNull($row['website']['url'], 'a Facebook profile must not be shown as WEBSITE');
        $this->assertSame('social', $row['website']['urlKind']);
        // Not lost — surfaced correctly, so the BD can still reach the page.
        $this->assertSame('http://www.facebook.com/BasconCakes', $row['website']['social']['facebook']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function business(string $name, string $website): array
    {
        return [
            'business_name' => $name,
            'website' => $website,
            'place_id' => 'places/'.md5($name),
            'source' => 'google_places',
            'latitude' => 10.6407,
            'longitude' => 122.9689,
            'city' => 'Bacolod City',
        ];
    }

    /** @param list<array<string, mixed>> $businesses */
    private function ingest(array $businesses): void
    {
        $user = User::factory()->create(['status' => 'active']);
        app(LeadImportService::class)->ingestDiscovered($businesses, (int) $user->id, false);
    }
}
