<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\NerbyProviderSetting;
use App\Modules\Crm\Models\ProviderSpendMeter;
use App\Modules\Crm\Services\Discovery\GooglePlacesIdProbe;
use App\Modules\Crm\Services\Discovery\GooglePlacesSpendGate;
use App\Modules\Crm\Services\Discovery\GooglePlacesTextSearch;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The spend limit as an ENFORCEMENT MECHANISM rather than a displayed number.
 *
 * THE ASSERTION THAT MATTERS IS `Http::assertSentCount`. A guard that returns the right status while
 * still issuing the request has spent the money and merely lied about it, so every test here checks
 * what reached Google, not only what the code returned. A limit that is respected in the response
 * and ignored on the wire is the exact failure this feature exists to prevent.
 *
 * Also pinned, because they are the ways a budget guard goes wrong in practice:
 *
 *  - **A refusal is not an empty area.** It surfaces as its own status and its own HTTP code, so
 *    nothing downstream can record "searched, found nothing" for a search that never happened.
 *  - **Rate refusals are refunded.** Google does not bill a 429; charging for one would let the
 *    orchestrator's own backoff consume the budget twice per retry.
 *  - **Both doors are locked.** The ids-only probe is a second call site, and a guard with an
 *    ungated second door is not a guard.
 *  - **OpenStreetMap is untouched.** The default provider must behave identically with the budget
 *    set to zero, because it costs nothing and shares no code with this path.
 */
class GooglePlacesSpendEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private const PLACES = '/api/v1/crm/discovery/places';

    private const CONFIG = '/api/v1/crm/discovery/config';

    private const SETTINGS = '/api/v1/crm/settings/integrations/nerby';

    private const FAKE_KEY = 'AIzaSy-test-not-a-real-google-key-00wxyz';

    private const BBOX = ['south' => 14.52, 'west' => 121.0, 'north' => 14.58, 'east' => 121.06];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function actingAsScraper(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('crm_admin');
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Google selected and live, with a budget written the way Settings writes one.
     *
     * `$limitUsd` of null means no budget at all — the unconfigured deployment.
     */
    private function googleLive(?float $limitUsd, bool $active = true): void
    {
        $options = ['price_per_1000_usd' => '35.00', 'free_tier_requests' => '0', 'safety_factor' => '1'];
        if ($limitUsd !== null) {
            $options['spend_limit_usd'] = (string) $limitUsd;
        }

        NerbyProviderSetting::query()->updateOrCreate(
            ['provider' => 'google_places'],
            [
                'is_active' => $active,
                'enabled' => true,
                'api_key' => self::FAKE_KEY,
                'key_last4' => mb_substr(self::FAKE_KEY, -4),
                'options' => $options,
            ],
        );
    }

    /** One Text Search page with a single place, enough to be a successful call. */
    private function fakeGoogleOk(): void
    {
        Http::fake([
            'places.googleapis.com/*' => Http::response([
                'places' => [[
                    'id' => 'ChIJtest', 'displayName' => ['text' => 'Test Bakery'],
                    'location' => ['latitude' => 14.55, 'longitude' => 121.02],
                    'types' => ['bakery'], 'primaryType' => 'bakery', 'addressComponents' => [],
                ]],
            ], 200),
        ]);
    }

    private function search(): array
    {
        return app(GooglePlacesTextSearch::class)->search('Pasig, Philippines', 'bakery', 20);
    }

    // ── The limit actually stops requests ────────────────────────────────────────────────────

    /** $0.035 at $35/1,000 buys exactly one request. The second must never leave the building. */
    public function test_a_search_beyond_the_budget_never_reaches_google(): void
    {
        $this->googleLive(0.035);
        $this->fakeGoogleOk();

        $first = $this->search();
        $this->assertSame(GooglePlacesTextSearch::STATUS_OK, $first['status']);
        Http::assertSentCount(1);

        $second = $this->search();

        $this->assertSame(GooglePlacesTextSearch::STATUS_SPEND_LIMITED, $second['status']);
        $this->assertSame([], $second['places']);
        Http::assertSentCount(1); // THE ASSERTION: still one. Nothing was spent on the refusal.
    }

    /** A zero budget issues no Google request of any kind — not even the free ids-only one. */
    public function test_a_zero_budget_makes_no_google_request_at_all(): void
    {
        $this->googleLive(0.0);
        $this->fakeGoogleOk();

        $this->assertSame(GooglePlacesTextSearch::STATUS_SPEND_LIMITED, $this->search()['status']);

        $probe = app(GooglePlacesIdProbe::class)->measure('bakery shop', 'Pasig, Philippines', 'bakery');
        $this->assertSame(GooglePlacesIdProbe::STATUS_SPEND_LIMITED, $probe['status']);

        Http::assertNothingSent();
    }

    /** No budget configured is today's behaviour: the search runs, unchanged. */
    public function test_an_unconfigured_budget_does_not_change_behaviour(): void
    {
        $this->googleLive(null);
        $this->fakeGoogleOk();

        $result = $this->search();

        $this->assertSame(GooglePlacesTextSearch::STATUS_OK, $result['status']);
        $this->assertCount(1, $result['places']);
        Http::assertSentCount(1);
    }

    /** Raising the limit in Settings frees the next request — no restart, no deploy. */
    public function test_raising_the_limit_in_settings_frees_the_next_request(): void
    {
        $this->googleLive(0.035);
        $this->fakeGoogleOk();

        $this->search();
        $this->assertSame(GooglePlacesTextSearch::STATUS_SPEND_LIMITED, $this->search()['status']);
        Http::assertSentCount(1);

        $this->googleLive(0.070); // management doubles it

        $this->assertSame(GooglePlacesTextSearch::STATUS_OK, $this->search()['status']);
        Http::assertSentCount(2);
    }

    // ── Refunds ──────────────────────────────────────────────────────────────────────────────

    /**
     * Google does not bill a 429, so neither do we — otherwise every retry would cost twice and the
     * orchestrator's own backoff would consume the budget it is waiting on.
     */
    public function test_a_rate_refusal_is_refunded(): void
    {
        $this->googleLive(0.070); // two requests
        Http::fake(['places.googleapis.com/*' => Http::response(['error' => ['status' => 'RESOURCE_EXHAUSTED']], 429)]);

        $this->assertSame(GooglePlacesTextSearch::STATUS_RATE_LIMITED, $this->search()['status']);

        $meter = ProviderSpendMeter::query()->where('provider', GooglePlacesSpendGate::PROVIDER)->first();
        $this->assertSame(0, $meter->micros_used, 'A refusal for rate costs nothing.');
    }

    /** A 403, by contrast, is not refunded — whether Google bills it is not ours to assume. */
    public function test_an_auth_failure_is_not_refunded(): void
    {
        $this->googleLive(0.070);
        Http::fake(['places.googleapis.com/*' => Http::response(['error' => ['status' => 'PERMISSION_DENIED']], 403)]);

        $this->assertSame(GooglePlacesTextSearch::STATUS_AUTH_FAILED, $this->search()['status']);

        $meter = ProviderSpendMeter::query()->where('provider', GooglePlacesSpendGate::PROVIDER)->first();
        $this->assertSame(35_000, $meter->micros_used, 'Over-counting spends less than allowed.');
    }

    // ── The refusal is legible end to end ────────────────────────────────────────────────────

    /**
     * The scraper's callback gets 402 and a named status — never a 502 that reads as "Google is
     * down", and never a 200 with an empty list that reads as "this area has no businesses".
     */
    public function test_the_places_endpoint_answers_402_with_a_named_status(): void
    {
        $this->actingAsScraper();
        $this->googleLive(0.0);
        $this->fakeGoogleOk();

        $response = $this->postJson(self::PLACES, [
            'area' => 'Pasig, Philippines', 'category' => 'bakery', 'limit' => 20, 'bbox' => self::BBOX,
        ]);

        $response->assertStatus(402);
        $response->assertJsonPath('data.executionStatus', GooglePlacesTextSearch::STATUS_SPEND_LIMITED);
        $response->assertJsonPath('data.businesses', []);
        Http::assertNothingSent();
    }

    /** The response must not carry the credential, on the refusal path as much as any other. */
    public function test_the_refusal_response_carries_no_credential(): void
    {
        $this->actingAsScraper();
        $this->googleLive(0.0);

        $response = $this->postJson(self::PLACES, [
            'area' => 'Pasig, Philippines', 'category' => 'bakery', 'limit' => 20, 'bbox' => self::BBOX,
        ]);

        $this->assertStringNotContainsString(self::FAKE_KEY, $response->getContent());
        $this->assertStringNotContainsString(mb_substr(self::FAKE_KEY, -8), $response->getContent());
    }

    // ── The Settings round trip: configurable without a deployment ───────────────────────────

    private function actingAsSuperAdmin(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('super_admin');
        Sanctum::actingAs($user);

        return $user;
    }

    /** The meter is readable through the existing settings endpoint — no second API was added. */
    public function test_the_settings_endpoint_reports_the_spend_state(): void
    {
        $this->actingAsSuperAdmin();
        $this->googleLive(10.0);

        $response = $this->getJson(self::SETTINGS);

        $response->assertOk();
        $google = collect($response->json('data.providers'))->firstWhere('provider', 'google_places');
        // assertEquals, not assertSame: JSON has no int/float distinction, so 10.0 arrives as 10.
        $this->assertEquals(10.0, $google['spend']['limitUsd']);
        $this->assertSame(0, $google['spend']['requestsUsed']);
        $this->assertFalse($google['spend']['unlimited']);
        $this->assertNotNull($google['spend']['requestsAllowed']);

        // No other provider grows a meaningless meter.
        $osm = collect($response->json('data.providers'))->firstWhere('provider', 'openstreetmap');
        $this->assertNull($osm['spend']);
    }

    /**
     * THE REQUIREMENT, END TO END: a super admin changes the amount through the existing Settings
     * API and the very next request is held to the new number. No deployment, no restart.
     */
    public function test_a_super_admin_changes_the_amount_and_enforcement_follows(): void
    {
        $this->actingAsSuperAdmin();
        $this->googleLive(0.035); // one request
        $this->fakeGoogleOk();

        $this->search();
        $this->assertSame(GooglePlacesTextSearch::STATUS_SPEND_LIMITED, $this->search()['status']);
        Http::assertSentCount(1);

        $this->putJson(self::SETTINGS.'/providers/google_places', [
            'options' => ['spend_limit_usd' => '0.070', 'price_per_1000_usd' => '35.00',
                'free_tier_requests' => '0', 'safety_factor' => '1'],
        ])->assertOk();

        $this->assertSame(GooglePlacesTextSearch::STATUS_OK, $this->search()['status']);
        Http::assertSentCount(2);
    }

    /** The environment ceiling is refused at the API, with a message a human can act on. */
    public function test_an_amount_above_the_environment_ceiling_is_refused(): void
    {
        $this->actingAsSuperAdmin();
        $this->googleLive(10.0);
        config(['nerby.spend.limit_max_usd' => 500]);

        $this->putJson(self::SETTINGS.'/providers/google_places', [
            'options' => ['spend_limit_usd' => '5000'],
        ])->assertStatus(422)->assertJsonValidationErrors('options.spend_limit_usd');
    }

    /**
     * RBAC IS UNCHANGED. These settings hold Vite SEO's own paid-for credential in the one CRM table
     * with no org_id, so the screen stays super-admin only — a client's CRM admin must not be able to
     * raise the limit on money that is not theirs.
     */
    public function test_a_crm_admin_cannot_read_or_change_the_spend_limit(): void
    {
        $this->actingAsScraper(); // crm_admin
        $this->googleLive(10.0);

        $this->getJson(self::SETTINGS)->assertForbidden();
        $this->putJson(self::SETTINGS.'/providers/google_places', [
            'options' => ['spend_limit_usd' => '500'],
        ])->assertForbidden();
    }

    // ── The freeze guarantee ─────────────────────────────────────────────────────────────────

    /**
     * OPENSTREETMAP IS UNTOUCHED. With the Google budget set to zero — the hardest setting — the
     * default provider still resolves, still reports itself effective, and no meter is opened,
     * because the baseline shares no code with the paid path and costs nothing to run.
     */
    public function test_the_openstreetmap_path_is_unaffected_by_a_zero_budget(): void
    {
        $this->actingAsScraper();
        $this->googleLive(0.0, active: false);
        NerbyProviderSetting::query()->updateOrCreate(['provider' => 'openstreetmap'], ['is_active' => true]);

        $response = $this->getJson(self::CONFIG);

        $response->assertOk();
        $response->assertJsonPath('data.effectiveProvider', 'openstreetmap');
        $this->assertSame(
            0,
            ProviderSpendMeter::query()->count(),
            'The baseline provider must never open a spend meter.',
        );
    }
}
