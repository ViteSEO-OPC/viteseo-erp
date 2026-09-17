<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\NerbyProviderSetting;
use App\Modules\Crm\Services\Discovery\GooglePlacesTextSearch;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Google Places API (New) as an optional Nearby discovery provider — Text Search, made by the CRM.
 *
 * Pinned from the directions it would actually break:
 *  - **The request is exactly the approved slice:** Text Search (never Nearby Search), `X-Goog-Api-Key`
 *    (never a bearer token), one field mask, `regionCode=PH`, strict type filtering when a type is
 *    known, and a location RESTRICTION to the area's bounding box.
 *  - **Records arrive in the discovery shape** with a `places/<id>` identity that cannot collide with OSM.
 *  - **A failed call is not an empty area,** and pagination stops at the page cap.
 *  - **The key never leaves:** not in any response body, not in any log line.
 *  - **OpenStreetMap stays the default and the fallback** — Google without a key is never effective.
 */
class GooglePlacesDiscoveryTest extends TestCase
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

    private function googleLive(bool $active = true): void
    {
        NerbyProviderSetting::query()->create([
            'provider' => 'google_places',
            'is_active' => $active,
            'enabled' => true,
            'api_key' => self::FAKE_KEY,
            'key_last4' => mb_substr(self::FAKE_KEY, -4),
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function place(string $id, string $name, array $overrides = []): array
    {
        return array_replace([
            'id' => $id,
            'displayName' => ['text' => $name, 'languageCode' => 'en'],
            'location' => ['latitude' => 14.5547, 'longitude' => 121.0244],
            'types' => ['pharmacy', 'health', 'point_of_interest', 'establishment'],
            'primaryType' => 'pharmacy',
            'addressComponents' => [
                ['longText' => 'Makati City', 'shortText' => 'Makati City', 'types' => ['locality', 'political']],
                ['longText' => 'Metro Manila', 'shortText' => 'Metro Manila', 'types' => ['administrative_area_level_2', 'political']],
                ['longText' => 'National Capital Region', 'shortText' => 'NCR', 'types' => ['administrative_area_level_1', 'political']],
                ['longText' => 'Philippines', 'shortText' => 'PH', 'types' => ['country', 'political']],
            ],
            'websiteUri' => 'https://www.mercurydrug.com/',
            'nationalPhoneNumber' => '(02) 8911 5000',
        ], $overrides);
    }

    /** @param  list<array<string, mixed>>  $places */
    private function page(array $places, ?string $next = null): array
    {
        return array_filter(['places' => $places, 'nextPageToken' => $next], fn ($v) => $v !== null);
    }

    private function search(array $payload = []): TestResponse
    {
        return $this->postJson(self::PLACES, array_merge([
            'area' => 'Makati City, Philippines',
            'category' => 'pharmacy',
            'limit' => 10,
            'includedType' => 'pharmacy',
            'bbox' => self::BBOX,
        ], $payload));
    }

    // --- bounded multi-query: breadth that cannot become a crawler -------------------------------

    public function test_an_unplanned_search_runs_exactly_one_query(): void
    {
        // The behaviour that predates planned queries, pinned: no plan means one search.
        $this->actingAsScraper();
        $this->googleLive();
        Http::fake(['*' => Http::response($this->page([$this->place('A', 'One')]))]);

        $this->search()->assertOk()->assertJsonPath('data.queriesExecuted', 1);

        Http::assertSentCount(1);
    }

    public function test_several_planned_queries_are_merged_and_deduplicated_on_place_id(): void
    {
        $this->actingAsScraper();
        $this->googleLive();
        Http::fake(['*' => Http::sequence()
            ->push($this->page([$this->place('A', 'One'), $this->place('B', 'Two')]))
            ->push($this->page([$this->place('B', 'Two'), $this->place('C', 'Three')]))]);

        $response = $this->search(['textQueries' => ['first phrasing', 'second phrasing']])->assertOk();

        Http::assertSentCount(2);
        $response->assertJsonPath('data.queriesExecuted', 2);
        // The business BOTH queries returned is one business. Variations of a niche overlap heavily,
        // so without dedup on Google's own id the second query would mostly re-deliver the first's.
        $this->assertSame(
            ['places/A', 'places/B', 'places/C'],
            array_column($response->json('data.businesses'), 'provider_id'),
        );
    }

    public function test_the_limit_is_a_total_across_queries_not_a_budget_for_each(): void
    {
        // THE PROPERTY THAT KEEPS THIS FROM BEING A CRAWLER. Once the caller has what it asked for,
        // the remaining phrases are never paid for.
        $this->actingAsScraper();
        $this->googleLive();
        Http::fake(['*' => Http::sequence()
            ->push($this->page([$this->place('A', 'One'), $this->place('B', 'Two'), $this->place('C', 'Three')]))
            ->push($this->page([$this->place('D', 'Four')]))]);

        $response = $this->search(['limit' => 3, 'textQueries' => ['one', 'two', 'three']])->assertOk();

        Http::assertSentCount(1);
        $response->assertJsonPath('data.queriesExecuted', 1);
        $this->assertCount(3, $response->json('data.businesses'));
    }

    public function test_phrases_differing_only_in_case_or_spacing_are_one_search(): void
    {
        $this->actingAsScraper();
        $this->googleLive();
        Http::fake(['*' => Http::response($this->page([$this->place('A', 'One')]))]);

        $this->search(['textQueries' => ['Same Phrase', '  same   phrase ', 'SAME PHRASE']])
            ->assertOk()
            ->assertJsonPath('data.queriesExecuted', 1);

        Http::assertSentCount(1);
    }

    public function test_more_planned_queries_than_the_ceiling_are_refused_outright(): void
    {
        // Refused at the request layer rather than trimmed downstream: a query count is a multiplier
        // on paid calls, so an oversized list is a bad request, not something to quietly shorten.
        $this->actingAsScraper();
        $this->googleLive();
        Http::fake();

        $this->search(['textQueries' => array_fill(0, GooglePlacesTextSearch::MAX_QUERIES + 1, 'phrase')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('textQueries');

        Http::assertNothingSent();
    }

    public function test_a_later_query_failing_keeps_what_earlier_queries_found(): void
    {
        $this->actingAsScraper();
        $this->googleLive();
        Http::fake(['*' => Http::sequence()
            ->push($this->page([$this->place('A', 'One')]))
            ->push(['error' => ['status' => 'INTERNAL']], 500)]);

        $response = $this->search(['textQueries' => ['one', 'two']])->assertOk();

        // Still a successful search — real results are in hand — but the failure is carried, not hidden.
        $response->assertJsonPath('data.executionStatus', GooglePlacesTextSearch::STATUS_OK);
        $this->assertCount(1, $response->json('data.businesses'));
        $this->assertStringContainsString('query_2', (string) $response->json('data.errorReason'));
    }

    // --- provider selection: optional, with OpenStreetMap as the fallback ------------------------

    public function test_a_credentialed_google_places_is_the_effective_provider(): void
    {
        $this->actingAsScraper();
        $this->googleLive();

        $this->getJson(self::CONFIG)->assertOk()
            ->assertJsonPath('data.effectiveProvider', 'google_places')
            ->assertJsonPath('data.isFallback', false);
    }

    public function test_google_places_without_a_key_falls_back_to_openstreetmap_and_refuses_searches(): void
    {
        $this->actingAsScraper();
        NerbyProviderSetting::query()->create(['provider' => 'google_places', 'is_active' => true, 'enabled' => true]);
        Http::fake();

        $this->getJson(self::CONFIG)->assertOk()
            ->assertJsonPath('data.effectiveProvider', 'openstreetmap')
            ->assertJsonPath('data.configuredProvider', 'google_places')
            ->assertJsonPath('data.isFallback', true);

        $this->search()->assertStatus(409)->assertJsonPath('data.businesses', []);
        Http::assertNothingSent();
    }

    public function test_a_search_is_refused_while_another_provider_is_effective(): void
    {
        $this->actingAsScraper();
        $this->googleLive(active: false); // stored and credentialed, but not the selected provider
        Http::fake();

        $this->search()->assertStatus(409)->assertJsonPath('data.effectiveProvider', 'openstreetmap');
        Http::assertNothingSent();
    }

    // --- the request ----------------------------------------------------------------------------

    public function test_the_request_is_a_text_search_with_the_key_header_field_mask_and_area_restriction(): void
    {
        $this->actingAsScraper();
        $this->googleLive();
        Http::fake(['places.googleapis.com/*' => Http::response($this->page([]))]);

        $this->search()->assertOk();

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->method() === 'POST'
                && $request->url() === 'https://places.googleapis.com/v1/places:searchText'
                && $request->header('X-Goog-Api-Key') === [self::FAKE_KEY]
                && $request->header('X-Goog-FieldMask') === [GooglePlacesTextSearch::FIELD_MASK]
                && ! $request->hasHeader('Authorization')
                && $body['textQuery'] === 'pharmacy in Makati City, Philippines'
                && $body['regionCode'] === 'PH'
                && $body['pageSize'] === 10
                && $body['includedType'] === 'pharmacy'
                && $body['strictTypeFiltering'] === true
                && $body['locationRestriction'] === ['rectangle' => [
                    'low' => ['latitude' => 14.52, 'longitude' => 121.0],
                    'high' => ['latitude' => 14.58, 'longitude' => 121.06],
                ]]
                && ! array_key_exists('locationBias', $body);
        });
    }

    public function test_the_field_mask_asks_for_no_ratings_reviews_or_hours(): void
    {
        foreach (['rating', 'review', 'Hours', 'photos', 'priceLevel'] as $excluded) {
            $this->assertStringNotContainsString($excluded, GooglePlacesTextSearch::FIELD_MASK);
        }
    }

    public function test_without_a_type_or_area_box_the_search_is_text_only(): void
    {
        $this->actingAsScraper();
        $this->googleLive();
        Http::fake(['places.googleapis.com/*' => Http::response($this->page([]))]);

        $this->search(['category' => 'car_repair', 'includedType' => null, 'bbox' => null])->assertOk();

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $body['textQuery'] === 'car repair in Makati City, Philippines'
                && ! array_key_exists('includedType', $body)
                && ! array_key_exists('strictTypeFiltering', $body)
                && ! array_key_exists('locationRestriction', $body);
        });
    }

    // --- normalization --------------------------------------------------------------------------

    public function test_places_are_normalized_into_the_discovery_shape(): void
    {
        $this->actingAsScraper();
        $this->googleLive();
        Http::fake(['places.googleapis.com/*' => Http::response($this->page([
            $this->place('ChIJ-mercury-makati', 'Mercury Drug - Makati Avenue'),
        ]))]);

        $this->search()->assertOk()
            ->assertJsonPath('data.executionStatus', 'ok')
            ->assertJsonPath('data.pages', 1)
            ->assertJsonPath('data.businesses', [[
                'provider_id' => 'places/ChIJ-mercury-makati',
                'name' => 'Mercury Drug - Makati Avenue',
                'latitude' => 14.5547,
                'longitude' => 121.0244,
                'website' => 'https://www.mercurydrug.com/',
                'phone' => '(02) 8911 5000',
                'city' => 'Makati City',
                'province' => 'Metro Manila',
                'region' => 'National Capital Region',
                'primary_type' => 'pharmacy',
                'types' => ['pharmacy', 'health', 'point_of_interest', 'establishment'],
            ]]);
    }

    public function test_missing_fields_become_null_and_unusable_places_are_dropped(): void
    {
        $this->actingAsScraper();
        $this->googleLive();
        Http::fake(['places.googleapis.com/*' => Http::response($this->page([
            ['id' => 'ChIJ-bare', 'displayName' => ['text' => 'Bare Pharmacy']],
            ['id' => 'ChIJ-no-name'],
            ['displayName' => ['text' => 'No Identity Pharmacy']],
            'not-a-place',
        ]))]);

        $businesses = $this->search()->assertOk()->json('data.businesses');

        $this->assertCount(1, $businesses);
        $this->assertSame([
            'provider_id' => 'places/ChIJ-bare',
            'name' => 'Bare Pharmacy',
            'latitude' => null,
            'longitude' => null,
            'website' => null,
            'phone' => null,
            'city' => null,
            'province' => null,
            'region' => null,
            'primary_type' => null,
            'types' => [],
        ], $businesses[0]);
    }

    // --- failures are not empty areas -----------------------------------------------------------

    /** @return array<string, array{int, string}> */
    public static function googleErrors(): array
    {
        return [
            'forbidden (API not enabled / key restricted)' => [403, GooglePlacesTextSearch::STATUS_AUTH_FAILED],
            'unauthenticated' => [401, GooglePlacesTextSearch::STATUS_AUTH_FAILED],
            'quota' => [429, GooglePlacesTextSearch::STATUS_RATE_LIMITED],
            'invalid request' => [400, GooglePlacesTextSearch::STATUS_INVALID_REQUEST],
            'server error' => [500, GooglePlacesTextSearch::STATUS_UNREACHABLE],
        ];
    }

    #[DataProvider('googleErrors')]
    public function test_a_google_error_is_a_502_with_a_status(int $http, string $status): void
    {
        $this->actingAsScraper();
        $this->googleLive();
        Http::fake(['places.googleapis.com/*' => Http::response(['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'x']], $http)]);

        $this->search()->assertStatus(502)
            ->assertJsonPath('data.executionStatus', $status)
            ->assertJsonPath('data.businesses', []);
    }

    public function test_a_connection_failure_is_unreachable(): void
    {
        $this->actingAsScraper();
        $this->googleLive();
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->search()->assertStatus(502)->assertJsonPath('data.executionStatus', GooglePlacesTextSearch::STATUS_UNREACHABLE);
    }

    // --- pagination -----------------------------------------------------------------------------

    public function test_pagination_follows_next_page_tokens_up_to_the_page_cap(): void
    {
        $this->actingAsScraper();
        $this->googleLive();
        $pageOf = fn (string $prefix): array => array_map(fn (int $i) => $this->place("{$prefix}-{$i}", "{$prefix} Pharmacy {$i}"), range(1, 20));
        Http::fake(['places.googleapis.com/*' => Http::sequence()
            ->push($this->page($pageOf('A'), 'token-2'))
            ->push($this->page($pageOf('B'), 'token-3'))
            ->push($this->page($pageOf('C'), 'token-4'))
            ->push($this->page($pageOf('D')))]);

        $response = $this->search(['limit' => 60])->assertOk();

        $this->assertCount(60, $response->json('data.businesses'));
        $response->assertJsonPath('data.pages', GooglePlacesTextSearch::MAX_PAGES);
        Http::assertSentCount(GooglePlacesTextSearch::MAX_PAGES);
        $tokens = [];
        Http::assertSent(function (Request $request) use (&$tokens): bool {
            $tokens[] = $request->data()['pageToken'] ?? null;

            return true;
        });
        $this->assertSame([null, 'token-2', 'token-3'], $tokens);
    }

    public function test_pagination_stops_once_the_limit_is_reached(): void
    {
        $this->actingAsScraper();
        $this->googleLive();
        Http::fake(['places.googleapis.com/*' => Http::response($this->page(
            array_map(fn (int $i) => $this->place("P-{$i}", "Pharmacy {$i}"), range(1, 20)),
            'token-2',
        ))]);

        $this->assertCount(5, $this->search(['limit' => 5])->assertOk()->json('data.businesses'));
        Http::assertSentCount(1);
    }

    public function test_a_later_page_failure_keeps_the_results_already_found(): void
    {
        $this->actingAsScraper();
        $this->googleLive();
        Http::fake(['places.googleapis.com/*' => Http::sequence()
            ->push($this->page(array_map(fn (int $i) => $this->place("P-{$i}", "Pharmacy {$i}"), range(1, 20)), 'token-2'))
            ->push(['error' => ['status' => 'RESOURCE_EXHAUSTED']], 429)]);

        $response = $this->search(['limit' => 40])->assertOk()->assertJsonPath('data.executionStatus', 'ok');

        $this->assertCount(20, $response->json('data.businesses'));
        $this->assertSame('rate_limited_on_page_2', $response->json('data.errorReason'));
    }

    // --- the key never leaves -------------------------------------------------------------------

    public function test_the_key_is_never_returned_or_logged(): void
    {
        $this->actingAsScraper();
        $this->googleLive();
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context);
        });
        Http::fake(['places.googleapis.com/*' => Http::sequence()
            ->push($this->page([$this->place('ChIJ-1', 'Pharmacy One')]))
            ->push(['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'API key not valid']], 403)]);

        $bodies = [
            $this->search()->content(),
            $this->search()->content(),
            $this->getJson(self::CONFIG)->content(),
        ];

        foreach ([...$bodies, ...$logged] as $text) {
            $this->assertStringNotContainsString(self::FAKE_KEY, $text);
            $this->assertStringNotContainsString('AIzaSy', $text);
        }
        $this->assertNotEmpty($logged, 'the 403 is logged — by status only');
    }

    // --- validation -----------------------------------------------------------------------------

    public function test_malformed_input_is_rejected_before_google_is_called(): void
    {
        $this->actingAsScraper();
        $this->googleLive();
        Http::fake();

        $this->search(['includedType' => 'Pharmacy; DROP'])->assertStatus(422)->assertJsonValidationErrors('includedType');
        $this->search(['bbox' => ['south' => 14.5]])->assertStatus(422)->assertJsonValidationErrors(['bbox.west', 'bbox.north', 'bbox.east']);
        $this->search(['limit' => 61])->assertStatus(422)->assertJsonValidationErrors('limit');
        Http::assertNothingSent();
    }

    // --- the settings page ----------------------------------------------------------------------

    public function test_the_connection_test_is_an_ids_only_search_with_the_key_header(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('super_admin');
        Sanctum::actingAs($admin);
        $this->googleLive();
        Http::fake(['places.googleapis.com/*' => Http::sequence()
            ->push($this->page([]))
            ->push(['error' => ['status' => 'PERMISSION_DENIED']], 403)]);

        $this->postJson(self::SETTINGS.'/providers/google_places/test');
        $this->assertSame('ok', NerbyProviderSetting::query()->where('provider', 'google_places')->value('last_test_status'));

        $this->postJson(self::SETTINGS.'/providers/google_places/test');
        $this->assertSame('auth_failed', NerbyProviderSetting::query()->where('provider', 'google_places')->value('last_test_status'));

        Http::assertSent(fn (Request $request): bool => $request->url() === GooglePlacesTextSearch::SEARCH_URL
            && $request->header('X-Goog-FieldMask') === [GooglePlacesTextSearch::PROBE_FIELD_MASK]
            && $request->header('X-Goog-Api-Key') === [self::FAKE_KEY]);

        $overview = $this->getJson(self::SETTINGS)->assertOk()->content();
        $this->assertStringNotContainsString(self::FAKE_KEY, $overview);
    }
}
