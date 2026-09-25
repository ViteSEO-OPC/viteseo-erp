<?php

namespace Tests\Feature\Crm;

use App\Models\Org;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\Crm\Console\Commands\EnrichDiscoveredWebsitesCommand;
use App\Modules\Crm\Models\DiscoveryRun;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadWebsiteCandidate;
use App\Modules\Crm\Models\NerbyProviderSetting;
use App\Modules\Crm\Services\Discovery\DiscoveryRunOrchestrator;
use App\Modules\Crm\Services\Discovery\ExecutedQueryLedger;
use App\Modules\Crm\Services\Discovery\NerbyServiceIdentity;
use App\Modules\Crm\Services\Discovery\ScraperProcessRunner;
use App\Support\CurrentOrg;
use App\Support\Entitlements;
use App\Support\ServiceTokens;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ActsAsNerbyService;
use Tests\TestCase;

/**
 * H-1 / H-2 / H-3 — the scraper's identity, its tenant binding, and who may write discovery data.
 *
 * BEFORE: the engine called back with one long-lived wildcard token owned by an orgless super_admin
 * (so every callback landed in the first org and the child held full platform admin), and every BD
 * could post discovered prospects and Website Intelligence findings directly.
 *
 * AFTER, pinned here:
 *  - a dedicated account with ONE permission, no org, no other role (H-1);
 *  - short-lived tokens bound to ONE org, confined to the scraper callbacks, revoked after use (H-1);
 *  - every callback lands in the org the token was issued for — never org 1 by fallback (H-2);
 *  - the write callbacks accept the service only; a forged Google identity is refused; discovery no
 *    longer writes an existing lead's website (H-3).
 */
class NerbyServiceIdentitySecurityTest extends TestCase
{
    use ActsAsNerbyService;
    use RefreshDatabase;

    private const GOOGLE_KEY = 'AIzaSy-test-not-a-real-google-key-00wxyz';

    private const BBOX = ['south' => 14.52, 'west' => 121.0, 'north' => 14.58, 'east' => 121.06];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Http::preventStrayRequests();
    }

    private function human(string $role, ?Org $org = null): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);
        if ($role !== 'super_admin') {
            // One org per user: the factory may already have placed them in the current org.
            $user->orgs()->sync([($org ?? $this->tenant())->id => ['is_default' => true]]);
        }

        return $user;
    }

    /** A second, fully-entitled customer org. */
    private function otherOrg(string $name = 'Kliyente Dos Inc'): Org
    {
        $org = Org::create(['name' => $name, 'is_active' => true]);
        Entitlements::enable($org->id, ['crm']);
        Subscription::create([
            'org_id' => $org->id, 'plan' => 'full', 'monthly_price' => 0, 'seat_limit' => 50,
            'status' => Subscription::ACTIVE, 'current_period_end' => now()->addYear(),
        ]);

        return $org;
    }

    private function osmRow(string $placeId = 'node/12345', array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Alpha Pharmacy',
            'place_id' => $placeId,
            'source' => 'openstreetmap',
            'city' => 'Makati',
            'latitude' => 14.5547,
            'longitude' => 121.0244,
        ], $overrides);
    }

    private function leadsIn(int $orgId): int
    {
        return CurrentOrg::runAs($orgId, fn (): int => Lead::query()->count());
    }

    // ── H-1: the identity ────────────────────────────────────────────────────────────────────

    public function test_the_service_account_is_least_privilege_and_belongs_to_no_org(): void
    {
        $user = app(NerbyServiceIdentity::class)->user();

        $this->assertSame([NerbyServiceIdentity::ROLE], $user->getRoleNames()->all());
        $this->assertSame(['crm.leads.import'], $user->getAllPermissions()->pluck('name')->all());
        $this->assertFalse($user->hasRole('super_admin'));
        $this->assertSame(0, $user->orgs()->count(), 'a member would appear in Team lists and use a seat');
    }

    public function test_provisioning_heals_a_drifted_account_back_to_least_privilege(): void
    {
        $identity = app(NerbyServiceIdentity::class);
        $user = $identity->user();
        $user->assignRole('super_admin');
        $user->givePermissionTo('crm.leads.delete');

        $user = $identity->user()->refresh();

        $this->assertSame([NerbyServiceIdentity::ROLE], $user->getRoleNames()->all());
        $this->assertTrue($user->getDirectPermissions()->isEmpty());
    }

    public function test_issued_tokens_are_scoped_org_bound_and_expiring(): void
    {
        $token = app(NerbyServiceIdentity::class)->issue($this->tenant()->id, 'run:abc', now()->addHour());
        $row = $token->accessToken;

        $this->assertSame([ServiceTokens::CALLBACK_ABILITY, 'org:'.$this->tenant()->id], $row->abilities);
        $this->assertNotContains('*', $row->abilities);
        $this->assertNotNull($row->expires_at);
        $this->assertSame('nerby:run:abc', $row->name);
    }

    public function test_a_discovery_run_mints_a_token_for_its_own_org_and_revokes_it(): void
    {
        Queue::fake();
        $other = $this->otherOrg();
        NerbyProviderSetting::query()->create([
            'provider' => 'google_places', 'is_active' => true, 'enabled' => true,
            'api_key' => self::GOOGLE_KEY, 'key_last4' => 'wxyz',
        ]);

        $admin = $this->human('crm_admin', $other);
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/crm/discovery/runs', ['locations' => ['Makati'], 'niches' => ['bakery']])
            ->assertStatus(202);
        $run = CurrentOrg::runAs($other->id, fn () => DiscoveryRun::query()->firstOrFail());
        $this->assertSame($other->id, (int) $run->org_id);

        $seen = [];
        $this->app->instance(ScraperProcessRunner::class, new class($seen) extends ScraperProcessRunner
        {
            public function __construct(public array &$seen) {}

            public function run(string $area, string $category, int $limit, string $provider, string $metricsPath,
                ?array $bbox = null, ?string $country = null, bool $aiStrategy = false, ?string $crmToken = null): array
            {
                $row = $crmToken ? PersonalAccessToken::findToken($crmToken) : null;
                $this->seen[] = ['token' => $crmToken, 'abilities' => $row?->abilities, 'user' => $row?->tokenable_id];

                return ['exitCode' => 0, 'executionStatus' => 'ok', 'errorReason' => null, 'discovered' => 0,
                    'inserted' => 0, 'duplicates' => 0, 'rejected' => 0, 'stderrTail' => '',
                    'strategyApplied' => null, 'strategyStatus' => null];
            }
        });

        CurrentOrg::runAs($other->id, fn () => $this->app->make(DiscoveryRunOrchestrator::class)->execute($run->uuid));

        $this->assertCount(1, $seen);
        $this->assertNotEmpty($seen[0]['token']);
        $this->assertSame([ServiceTokens::CALLBACK_ABILITY, 'org:'.$other->id], $seen[0]['abilities']);
        $this->assertSame(app(NerbyServiceIdentity::class)->user()->id, $seen[0]['user']);
        $this->assertNull(PersonalAccessToken::findToken($seen[0]['token']), 'the run token must be revoked');
    }

    // ── H-1: the token reaches its callbacks and nothing else ────────────────────────────────

    public function test_a_service_token_is_refused_everywhere_outside_its_callbacks(): void
    {
        $this->actingAsNerbyService();

        $this->getJson('/api/v1/crm/discovery/config')->assertOk();

        foreach ([
            ['GET', '/api/v1/crm/leads'],
            ['GET', '/api/v1/crm/nearby-clients'],
            ['GET', '/api/v1/crm/discovery/runs'],
            ['POST', '/api/v1/crm/discovery/runs'],
            ['GET', '/api/v1/crm/settings/integrations/nerby'],
            ['GET', '/api/v1/crm/users'],
            ['GET', '/api/v1/crm/me'],
            ['GET', '/api/v1/auth/me'],
        ] as [$method, $uri]) {
            $this->json($method, $uri)->assertForbidden();
        }
    }

    public function test_a_wildcard_or_unbound_service_token_is_refused_even_on_its_callbacks(): void
    {
        $user = app(NerbyServiceIdentity::class)->user();

        foreach ([
            ['*'],
            [ServiceTokens::CALLBACK_ABILITY],
            [ServiceTokens::CALLBACK_ABILITY, 'org:1', 'org:2'],
            ['org:'.$this->tenant()->id],
        ] as $abilities) {
            $plain = $user->createToken('forged', $abilities, now()->addHour())->plainTextToken;
            $this->app['auth']->forgetGuards();
            $this->withToken($plain)->getJson('/api/v1/crm/discovery/config')->assertForbidden();
        }
    }

    public function test_an_expired_service_token_is_rejected(): void
    {
        $token = app(NerbyServiceIdentity::class)->issue($this->tenant()->id, 'old', now()->subMinute());
        $this->withToken($token->plainTextToken)->getJson('/api/v1/crm/discovery/config')->assertUnauthorized();
    }

    public function test_a_humans_token_cannot_choose_its_tenant(): void
    {
        $other = $this->otherOrg();
        $admin = $this->human('crm_admin');
        // A human's personal token claiming another org is NOT honoured: only service roles bind.
        $plain = $admin->createToken('sneaky', ['*', 'org:'.$other->id], now()->addHour())->plainTextToken;

        CurrentOrg::runAs($other->id, fn () => Lead::factory()->create(['created_by' => $admin->id, 'business_name' => 'Other Org Lead']));

        $this->app['auth']->forgetGuards();
        CurrentOrg::clear();
        $names = collect($this->withToken($plain)->getJson('/api/v1/crm/leads')->assertOk()->json('data'))
            ->pluck('business_name');
        $this->assertNotContains('Other Org Lead', $names);
    }

    // ── H-2: tenant isolation through the callbacks ──────────────────────────────────────────

    public function test_a_token_bound_to_an_org_ingests_into_that_org_only(): void
    {
        $other = $this->otherOrg();

        $this->ingestDiscovered(['businesses' => [$this->osmRow('node/777')]], $other->id)->assertOk();

        $this->assertSame(1, $this->leadsIn($other->id));
        $this->assertSame(0, $this->leadsIn($this->tenant()->id), 'nothing may land in the provider org by fallback');
    }

    public function test_a_token_bound_to_one_org_never_sees_anothers_queue(): void
    {
        $other = $this->otherOrg();
        $mine = CurrentOrg::runAs($this->tenant()->id, fn () => Lead::factory()->create([
            'created_by' => $this->human('crm_admin')->id, 'source' => 'openstreetmap',
            'website' => 'https://tenant-a.example', 'qualified_at' => null,
        ]));

        $this->actingAsNerbyService($other->id);
        $ids = collect($this->getJson('/api/v1/crm/website-enrichment/queue?limit=50')->assertOk()->json('data'))->pluck('id');
        $this->assertNotContains((string) $mine->id, $ids->map(fn ($id) => (string) $id));

        // And a lead id from another org is simply not found through the token.
        $this->putJson("/api/v1/crm/leads/{$mine->id}/website-enrichment", ['homepage_status' => 200])->assertNotFound();
    }

    /**
     * A token reaches a lead of ITS OWN org through the `{lead}`-bound callbacks.
     *
     * Route-model binding runs as part of the request, so the tenant must already be pinned when
     * `{lead}` is resolved. The service user belongs to no org, so nothing but the token's binding
     * can name that tenant — resolve the lead first and it resolves in no org and 404s. A real
     * request starts with no org pinned; this test clears it to match, because the org the test's
     * own setup pinned would otherwise mask exactly that ordering.
     */
    public function test_a_token_reaches_its_own_orgs_lead_through_the_lead_bound_callbacks(): void
    {
        $lead = CurrentOrg::runAs($this->tenant()->id, fn () => Lead::factory()->create([
            'created_by' => $this->human('crm_admin')->id, 'source' => 'openstreetmap',
            'website' => null, 'qualified_at' => null,
        ]));

        $this->actingAsNerbyService($this->tenant()->id);
        CurrentOrg::clear();

        $this->postJson("/api/v1/crm/leads/{$lead->id}/website-candidates", [
            'candidates' => [], 'tiers_run' => ['probe'],
        ])->assertOk();

        // The audit write-back shares the ordering: reaching validation (not a 404) proves the lead
        // resolved in the token's org.
        CurrentOrg::clear();
        $this->putJson("/api/v1/crm/leads/{$lead->id}/website-enrichment", [])
            ->assertUnprocessable();
    }

    public function test_the_query_ledger_is_written_to_the_tokens_org(): void
    {
        $other = $this->otherOrg();
        NerbyProviderSetting::query()->create([
            'provider' => 'google_places', 'is_active' => true, 'enabled' => true,
            'api_key' => self::GOOGLE_KEY, 'key_last4' => 'wxyz',
        ]);
        Http::fake(['places.googleapis.com/*' => Http::response(['places' => []])]);

        $this->actingAsNerbyService($other->id);
        $this->postJson('/api/v1/crm/discovery/places', [
            'area' => 'Makati City', 'category' => 'bakery', 'limit' => 5, 'bbox' => self::BBOX,
            'textQueries' => ['bakery'],
        ])->assertOk();

        $ledger = app(ExecutedQueryLedger::class);
        $this->assertNotSame([], CurrentOrg::runAs($other->id, fn () => $ledger->covered('bakery', 'Makati City')));
        $this->assertSame([], CurrentOrg::runAs($this->tenant()->id, fn () => $ledger->covered('bakery', 'Makati City')));
    }

    public function test_enrichment_runs_one_pass_per_entitled_org_each_with_its_own_token(): void
    {
        $other = $this->otherOrg();
        Org::create(['name' => 'No CRM Here', 'is_active' => true]); // not entitled: skipped

        // Read each pass's token WHILE it is live (it is revoked when the pass ends).
        $bindings = [];
        Process::fake(function ($process) use (&$bindings) {
            $row = PersonalAccessToken::findToken((string) ($process->environment['CRM_TOKEN'] ?? ''));
            $bindings[] = $row?->abilities;

            return Process::result();
        });

        $this->artisan(EnrichDiscoveredWebsitesCommand::class, ['--audit' => 1, '--search' => 0])->assertSuccessful();

        $this->assertEqualsCanonicalizing([
            [ServiceTokens::CALLBACK_ABILITY, 'org:'.$this->tenant()->id],
            [ServiceTokens::CALLBACK_ABILITY, 'org:'.$other->id],
        ], $bindings, 'one pass per entitled org, each bound to that org and no other');
        $this->assertSame(0, PersonalAccessToken::query()->count(), 'every pass token is revoked');
    }

    // ── H-3: machine-only writes ─────────────────────────────────────────────────────────────

    public function test_no_human_role_can_call_the_scraper_write_callbacks(): void
    {
        $lead = CurrentOrg::runAs($this->tenant()->id, fn () => Lead::factory()->create([
            'created_by' => $this->human('crm_admin')->id, 'source' => 'openstreetmap',
        ]));

        foreach (['crm_bd', 'crm_admin', 'super_admin'] as $role) {
            $this->app['auth']->forgetGuards();
            Sanctum::actingAs($this->human($role));

            $this->postJson('/api/v1/crm/leads/discovered', ['businesses' => [$this->osmRow()]])->assertForbidden();
            $this->getJson('/api/v1/crm/website-enrichment/queue')->assertForbidden();
            $this->putJson("/api/v1/crm/leads/{$lead->id}/website-enrichment", ['homepage_status' => 200])->assertForbidden();
            $this->getJson('/api/v1/crm/website-evidence/queue')->assertForbidden();
            $this->postJson("/api/v1/crm/leads/{$lead->id}/website-candidates", ['candidates' => []])->assertForbidden();
        }

        $this->assertSame(1, Lead::query()->count(), 'no forged prospect was created');
    }

    public function test_the_discovery_endpoints_keep_their_human_access_unchanged(): void
    {
        foreach (['crm_admin' => 200, 'super_admin' => 200, 'crm_bd' => 403] as $role => $status) {
            $this->app['auth']->forgetGuards();
            Sanctum::actingAs($this->human($role));
            $this->postJson('/api/v1/crm/discovery/places', [
                'area' => 'Makati City', 'category' => 'bakery', 'limit' => 5, 'bbox' => self::BBOX,
            ])->assertStatus($status === 200 ? 409 : 403); // 409 = Google not the effective provider
        }
    }

    public function test_an_unattested_google_identity_is_refused(): void
    {
        $this->actingAsNerbyService();

        $result = $this->postJson('/api/v1/crm/leads/discovered', ['businesses' => [[
            'business_name' => 'Totally Real Bakery', 'place_id' => 'places/INVENTED', 'source' => 'google_places',
            'latitude' => 14.55, 'longitude' => 121.02, 'phone' => '0917 000 0000',
        ]]])->assertOk()->json('data');

        $this->assertSame(0, $result['importedCount']);
        $this->assertSame(1, $result['summary']['unverified']);
        $this->assertSame(0, Lead::query()->count());
    }

    public function test_the_real_places_path_attests_and_a_tampered_field_is_refused(): void
    {
        NerbyProviderSetting::query()->create([
            'provider' => 'google_places', 'is_active' => true, 'enabled' => true,
            'api_key' => self::GOOGLE_KEY, 'key_last4' => 'wxyz',
        ]);
        Http::fake(['places.googleapis.com/*' => Http::response(['places' => [[
            'id' => 'ChIJreal1', 'displayName' => ['text' => 'Real Bakery'],
            'location' => ['latitude' => 14.5547, 'longitude' => 121.0244],
            'nationalPhoneNumber' => '(02) 8123 4567', 'websiteUri' => 'https://realbakery.example.ph',
            'types' => ['bakery'], 'primaryType' => 'bakery',
        ]]])]);

        $this->actingAsNerbyService();
        $place = $this->postJson('/api/v1/crm/discovery/places', [
            'area' => 'Makati City', 'category' => 'bakery', 'limit' => 5, 'bbox' => self::BBOX,
        ])->assertOk()->json('data.businesses.0');

        $row = [
            'business_name' => $place['name'], 'place_id' => $place['provider_id'], 'source' => 'google_places',
            'latitude' => $place['latitude'], 'longitude' => $place['longitude'],
            'phone' => $place['phone'], 'website' => $place['website'],
        ];

        foreach ([
            ['latitude' => 14.9],                             // moved pin
            ['phone' => '0999 999 9999'],                     // swapped phone
            ['website' => 'https://attacker.example'],        // swapped website
            ['business_name' => 'Somebody Else Entirely'],    // swapped identity
            ['source' => 'openstreetmap', 'place_id' => 'places/ChIJreal1x'], // unknown id
        ] as $tamper) {
            $result = $this->postJson('/api/v1/crm/leads/discovered', ['businesses' => [array_merge($row, $tamper)]])
                ->assertOk()->json('data');
            $this->assertSame(0, $result['importedCount'], 'tampered row stored: '.json_encode($tamper));
        }

        $this->postJson('/api/v1/crm/leads/discovered', ['businesses' => [$row]])
            ->assertOk()->assertJsonPath('data.importedCount', 1);
    }

    public function test_an_attestation_cannot_be_replayed_by_another_token(): void
    {
        $this->actingAsNerbyService();
        $row = ['business_name' => 'Replay Bakery', 'place_id' => 'places/REPLAY', 'source' => 'google_places'];
        $this->attestAsGoogle([$row]);

        $this->actingAsNerbyService(); // a different token (another run)
        $this->postJson('/api/v1/crm/leads/discovered', ['businesses' => [$row]])
            ->assertOk()->assertJsonPath('data.importedCount', 0);
    }

    public function test_identity_shapes_no_provider_produces_are_refused(): void
    {
        $this->actingAsNerbyService();

        $result = $this->postJson('/api/v1/crm/leads/discovered', ['businesses' => [
            $this->osmRow('node/1'),                                        // valid OSM
            $this->osmRow('evil-123', ['business_name' => 'B']),            // invented shape
            $this->osmRow('node/2', ['source' => 'google_places', 'business_name' => 'C']), // relabelled
            $this->osmRow('way/0', ['business_name' => 'D']),               // not a real element id
        ]])->assertOk()->json('data');

        $this->assertSame(1, $result['importedCount']);
        $this->assertSame(3, $result['summary']['unverified']);
    }

    public function test_discovery_never_overwrites_an_existing_leads_website(): void
    {
        $this->ingestDiscovered(['businesses' => [$this->osmRow('node/55', ['website' => 'https://audited.example'])]])->assertOk();
        $lead = Lead::query()->firstOrFail();

        $this->ingestDiscovered(['businesses' => [$this->osmRow('node/55', ['website' => 'https://attacker.example'])]])
            ->assertOk()->assertJsonPath('data.summary.updated', 0);

        $this->assertSame('https://audited.example', $lead->refresh()->website);
        $candidate = LeadWebsiteCandidate::query()->where('lead_id', $lead->id)->firstOrFail();
        $this->assertSame('https://attacker.example', $candidate->url);
        $this->assertNull($candidate->verified, 'a candidate is evidence to verify, never a verdict');
    }
}
