<?php

namespace Tests\Feature\Crm;

use App\Models\Org;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadWebsiteCandidate;
use App\Modules\Crm\Services\Discovery\NerbyServiceIdentity;
use App\Support\CurrentOrg;
use App\Support\Entitlements;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `crm:nerby-import` — landing an EXISTING NERBY dataset into one org, operator-only.
 *
 * What these tests pin: a bare run writes no lead; a write needs the file's exact SHA-256; only
 * factual provider fields are copied (never local CRM state); a place_id the TARGET org already holds
 * — soft-deleted included — is skipped and that lead is left exactly as it was; a business name alone
 * never makes a duplicate; `source` and `discovery_ai_assisted` filter separately; and no provider is
 * ever called.
 */
class NerbyDatasetImportTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        // NO PROVIDER IS CALLED: any outbound HTTP request fails the test.
        Http::preventStrayRequests();

        $this->dir = sys_get_temp_dir().'/nerby-import-test-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);

        parent::tearDown();
    }

    /** One business as the clean Google Places export carries it. */
    private function googleRow(string $placeId, array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Bakery '.$placeId,
            'contact_name' => 'Bakery '.$placeId,
            'email' => null,
            'phone' => '0917 000 0000',
            'industry' => 'Bakery',
            'region' => 'Western Visayas',
            'province' => 'Negros Occidental',
            'city' => 'Bacolod',
            'latitude' => 10.6765,
            'longitude' => 122.9509,
            'place_id' => $placeId,
            'source' => 'google_places',
            'website' => null,
            'facebook' => null,
            'instagram' => null,
            'discovery_ai_assisted' => null,
        ], $overrides);
    }

    private function osmRow(string $placeId, array $overrides = []): array
    {
        return $this->googleRow($placeId, array_merge(['source' => 'openstreetmap', 'business_name' => 'Pharmacy '.$placeId], $overrides));
    }

    /** @param  list<array<string, mixed>|string>  $lines  arrays are JSON-encoded; strings are written raw */
    private function file(array $lines): string
    {
        $path = $this->dir.'/dataset-'.bin2hex(random_bytes(4)).'.jsonl';
        file_put_contents($path, implode("\n", array_map(
            fn ($l): string => is_string($l) ? $l : json_encode($l, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $lines,
        ))."\n");

        return $path;
    }

    /** @return array{exit: int, manifest: array<string, mixed>} */
    private function import(string $path, array $options = []): array
    {
        $manifestPath = $this->dir.'/manifest-'.bin2hex(random_bytes(4)).'.json';
        $exit = $this->artisan('crm:nerby-import', array_merge([
            'path' => $path,
            '--org' => (string) $this->tenant()->id,
            '--source' => 'google_places,openstreetmap,openai',
            '--manifest' => $manifestPath,
        ], $options))->run();

        $manifest = is_file($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : [];

        return ['exit' => $exit, 'manifest' => $manifest];
    }

    private function confirmed(string $path, array $options = []): array
    {
        return $this->import($path, array_merge(['--confirm' => hash_file('sha256', $path)], $options));
    }

    private function otherOrg(): Org
    {
        $org = Org::create(['name' => 'Kliyente Dos Inc', 'is_active' => true]);
        Entitlements::enable($org->id, ['crm']);
        Subscription::create([
            'org_id' => $org->id, 'plan' => 'full', 'monthly_price' => 0, 'seat_limit' => 50,
            'status' => Subscription::ACTIVE, 'current_period_end' => now()->addYear(),
        ]);

        return $org;
    }

    /** Who created the pre-existing leads these tests start from — the factory assumes a user exists. */
    private function creator(): User
    {
        return User::query()->where('email', 'existing-lead-owner@test.local')->first()
            ?? User::factory()->create(['email' => 'existing-lead-owner@test.local']);
    }

    private function lead(string $placeId): Lead
    {
        return Lead::query()->where('place_id', $placeId)->sole();
    }

    // ── dry run + confirmation ─────────────────────────────────────────────────────────────────

    public function test_a_bare_run_is_a_dry_run_that_writes_no_lead_and_does_not_provision_the_service_account(): void
    {
        $path = $this->file([$this->googleRow('places/A'), $this->googleRow('places/B')]);

        $run = $this->import($path);

        $this->assertSame(0, $run['exit']);
        $this->assertSame(0, Lead::query()->count());
        $this->assertSame('dry_run', $run['manifest']['mode']);
        $this->assertSame(hash_file('sha256', $path), $run['manifest']['input']['sha256']);
        // The bytes the validation pass read hash to the same value the confirmation is checked against.
        $this->assertSame($run['manifest']['input']['sha256'], $run['manifest']['input']['sha256_read']);
        $this->assertSame(2, $run['manifest']['counts']['eligible']);
        $this->assertSame(0, $run['manifest']['counts']['imported']);
        $this->assertNull(User::query()->where('email', config('discovery.service_account.email', 'nerby-service@crm.local'))->first());
    }

    public function test_a_write_is_refused_unless_the_confirmation_is_the_files_exact_sha256(): void
    {
        $path = $this->file([$this->googleRow('places/A')]);

        $run = $this->import($path, ['--confirm' => str_repeat('0', 64)]);

        $this->assertSame(1, $run['exit']);
        $this->assertSame('refused', $run['manifest']['status']);
        $this->assertSame(0, Lead::query()->count());
    }

    public function test_a_confirmed_write_imports_and_reports_the_counts(): void
    {
        $path = $this->file([$this->googleRow('places/A'), $this->googleRow('places/B')]);

        $run = $this->confirmed($path);

        $this->assertSame(0, $run['exit']);
        $this->assertSame('ok', $run['manifest']['status']);
        $this->assertSame(2, $run['manifest']['counts']['imported']);
        $this->assertSame(2, Lead::query()->where('source', 'google_places')->count());
    }

    // ── what is copied, and what never is ─────────────────────────────────────────────────────

    public function test_factual_provider_fields_are_preserved_and_local_crm_state_is_never_copied(): void
    {
        $owner = User::factory()->create();
        $path = $this->file([$this->googleRow('places/A', [
            'latitude' => 10.6729471,
            'longitude' => 122.9456394,
            'facebook' => 'https://facebook.com/bakery-a',
            // Local CRM state from the source database — all of it must be dropped.
            'id' => 4242,
            'org_id' => 999,
            'created_by' => $owner->id,
            'assigned_to' => $owner->id,
            'qualified_by' => $owner->id,
            'qualified_at' => '2026-09-01 00:00:00',
            'score' => 99,
            'priority' => 'high',
            'status' => 'won',
            'website_status' => 'has-website',
        ])]);

        $run = $this->confirmed($path);

        $lead = $this->lead('places/A');
        $service = app(NerbyServiceIdentity::class)->user();

        $this->assertSame('google_places', $lead->source);
        $this->assertSame(10.6729471, (float) $lead->latitude);
        $this->assertSame(122.9456394, (float) $lead->longitude);
        $this->assertSame('https://facebook.com/bakery-a', $lead->facebook);
        $this->assertSame('Bakery places/A', $lead->business_name);

        $this->assertNotSame(4242, $lead->id);
        $this->assertSame($this->tenant()->id, (int) $lead->org_id);
        $this->assertSame($service->id, (int) $lead->created_by);
        $this->assertNull($lead->assigned_to);
        $this->assertNull($lead->qualified_by);
        $this->assertNull($lead->qualified_at);
        $this->assertSame(0, (int) $lead->score);
        $this->assertSame('medium', $lead->priority);
        $this->assertSame('new', $lead->status);
        $this->assertSame('website-unknown', $lead->website_status);

        foreach (['id', 'org_id', 'created_by', 'assigned_to', 'qualified_by', 'qualified_at', 'score', 'priority', 'status', 'website_status'] as $field) {
            $this->assertSame(1, $run['manifest']['dropped_fields'][$field] ?? null, "{$field} should be reported as dropped");
        }
    }

    public function test_an_explicit_actor_must_belong_to_the_target_org(): void
    {
        // UserFactory files every user under the first org; move this one to another tenant.
        $outsider = User::factory()->create();
        $outsider->orgs()->sync([$this->otherOrg()->id]);
        $path = $this->file([$this->googleRow('places/A')]);

        $run = $this->confirmed($path, ['--actor' => (string) $outsider->id]);
        $this->assertSame(2, $run['exit']);
        $this->assertSame(0, Lead::query()->count());

        $member = User::factory()->create(); // filed under the tenant by UserFactory
        $run = $this->confirmed($path, ['--actor' => (string) $member->id]);
        $this->assertSame(0, $run['exit']);
        $this->assertSame($member->id, (int) $this->lead('places/A')->created_by);
    }

    // ── existing leads are never touched ──────────────────────────────────────────────────────

    public function test_an_existing_google_place_id_is_skipped_and_that_lead_is_left_exactly_as_it_was(): void
    {
        $existing = Lead::factory()->create(['created_by' => $this->creator()->id,
            'place_id' => 'places/A', 'source' => 'google_places', 'website' => 'https://old.example',
            'business_name' => 'Original Name',
        ]);
        $before = DB::table('crm_leads')->where('id', $existing->id)->first();

        $path = $this->file([
            $this->googleRow('places/A', ['business_name' => 'Renamed', 'website' => 'https://new.example', 'discovery_ai_assisted' => true]),
            $this->googleRow('places/B'),
        ]);

        $run = $this->confirmed($path);

        $this->assertSame(1, $run['manifest']['counts']['imported']);
        $this->assertSame(1, $run['manifest']['reasons']['skipped']['existing_place_id']['count']);
        $this->assertSame([1], $run['manifest']['reasons']['skipped']['existing_place_id']['lines']);
        $this->assertEquals($before, DB::table('crm_leads')->where('id', $existing->id)->first());
        $this->assertSame(0, LeadWebsiteCandidate::query()->count());
        $this->assertSame(0, DB::table('crm_lead_discovery_lanes')->count());
        $this->assertSame(0, $run['manifest']['ingest']['website_candidates']);
    }

    public function test_a_soft_deleted_place_id_counts_as_existing_and_is_never_reimported(): void
    {
        $deleted = Lead::factory()->create(['created_by' => $this->creator()->id, 'place_id' => 'places/A', 'source' => 'google_places']);
        $deleted->delete();

        $run = $this->confirmed($this->file([$this->googleRow('places/A')]));

        $this->assertSame(0, $run['manifest']['counts']['imported']);
        $this->assertSame(1, $run['manifest']['reasons']['skipped']['existing_place_id_soft_deleted']['count']);
        $this->assertSame(0, Lead::query()->count());
        $this->assertTrue(Lead::withTrashed()->findOrFail($deleted->id)->trashed());
    }

    public function test_a_second_identical_run_imports_nothing(): void
    {
        $path = $this->file([$this->googleRow('places/A'), $this->osmRow('node/1')]);

        $this->assertSame(2, $this->confirmed($path)['manifest']['counts']['imported']);
        $second = $this->confirmed($path);

        $this->assertSame(0, $second['manifest']['counts']['imported']);
        $this->assertSame(2, $second['manifest']['reasons']['skipped']['existing_place_id']['count']);
        $this->assertSame(2, Lead::query()->count());
    }

    public function test_a_place_id_repeated_inside_the_file_lands_once(): void
    {
        $run = $this->confirmed($this->file([$this->googleRow('places/A'), $this->googleRow('places/A')]));

        $this->assertSame(1, $run['manifest']['counts']['imported']);
        $this->assertSame([2], $run['manifest']['reasons']['skipped']['duplicate_place_id_in_file']['lines']);
    }

    public function test_a_matching_business_name_alone_is_never_treated_as_a_duplicate(): void
    {
        Lead::factory()->create(['created_by' => $this->creator()->id, 'place_id' => 'node/7', 'source' => 'openstreetmap', 'business_name' => 'Same Name Bakery']);

        $run = $this->confirmed($this->file([$this->googleRow('places/A', ['business_name' => 'Same Name Bakery'])]));

        $this->assertSame(1, $run['manifest']['counts']['imported']);
        $this->assertSame(2, Lead::query()->where('business_name', 'Same Name Bakery')->count());
    }

    // ── organization scoping ───────────────────────────────────────────────────────────────────

    public function test_duplicates_are_judged_against_the_target_org_only(): void
    {
        $other = $this->otherOrg();
        CurrentOrg::runAs($other->id, fn () => Lead::factory()->create(['created_by' => $this->creator()->id, 'place_id' => 'places/A', 'source' => 'google_places']));

        $run = $this->confirmed($this->file([$this->googleRow('places/A')]));

        $this->assertSame(1, $run['manifest']['counts']['imported']);
        $this->assertSame($this->tenant()->id, (int) $this->lead('places/A')->org_id);
        $this->assertSame(1, CurrentOrg::runAs($other->id, fn (): int => Lead::query()->where('place_id', 'places/A')->count()));
    }

    public function test_the_import_lands_in_the_named_org_and_no_other(): void
    {
        $other = $this->otherOrg();

        $run = $this->confirmed($this->file([$this->googleRow('places/A')]), ['--org' => (string) $other->id]);

        $this->assertSame(0, $run['exit']);
        $this->assertSame(0, Lead::query()->count());
        $this->assertSame(1, CurrentOrg::runAs($other->id, fn (): int => Lead::query()->where('place_id', 'places/A')->count()));
    }

    public function test_the_org_is_required_and_must_exist(): void
    {
        $path = $this->file([$this->googleRow('places/A')]);

        $this->assertSame(2, $this->artisan('crm:nerby-import', ['path' => $path, '--source' => 'google_places'])->run());
        $this->assertSame(2, $this->artisan('crm:nerby-import', ['path' => $path, '--source' => 'google_places', '--org' => '99999'])->run());
    }

    // ── source and AI flag are separate filters ───────────────────────────────────────────────

    public function test_source_filter_selects_providers(): void
    {
        $path = $this->file([$this->googleRow('places/A'), $this->osmRow('node/1'), $this->osmRow('node/2', ['source' => 'openai'])]);

        $run = $this->confirmed($path, ['--source' => 'openstreetmap']);

        $this->assertSame(1, $run['manifest']['counts']['imported']);
        $this->assertSame(2, $run['manifest']['reasons']['filtered']['source_not_selected']['count']);
        $this->assertSame(['openstreetmap'], Lead::query()->pluck('source')->all());
    }

    public function test_the_ai_flag_is_a_separate_filter_and_is_preserved(): void
    {
        $path = $this->file([
            $this->googleRow('places/A', ['discovery_ai_assisted' => true]),
            $this->googleRow('places/B', ['discovery_ai_assisted' => false]),
            $this->googleRow('places/C'),
        ]);

        $run = $this->confirmed($path, ['--source' => 'google_places', '--ai-assisted' => 'true']);

        $this->assertSame(1, $run['manifest']['counts']['imported']);
        $this->assertSame(2, $run['manifest']['reasons']['filtered']['ai_flag_not_selected']['count']);
        $lead = $this->lead('places/A');
        $this->assertTrue($lead->discovery_ai_assisted);
        $this->assertSame('google_places', $lead->source);
    }

    public function test_ai_assisted_is_not_accepted_as_a_source(): void
    {
        $path = $this->file([$this->googleRow('places/A')]);

        $this->assertSame(2, $this->import($path, ['--source' => 'discovery_ai_assisted'])['exit']);
    }

    public function test_an_openai_row_keeps_its_source_and_osm_identity(): void
    {
        $run = $this->confirmed($this->file([$this->osmRow('node/9', ['source' => 'openai', 'discovery_ai_assisted' => true])]));

        $this->assertSame(1, $run['manifest']['counts']['imported']);
        $lead = $this->lead('node/9');
        $this->assertSame('openai', $lead->source);
        $this->assertTrue($lead->discovery_ai_assisted);
    }

    // ── validation before writing ─────────────────────────────────────────────────────────────

    public function test_invalid_lines_are_rejected_with_line_numbers_and_block_the_write(): void
    {
        $path = $this->file([
            $this->googleRow('places/A'),
            "{\"business_name\": \"Caf\x92 Uno\", \"place_id\": \"places/X\", \"source\": \"google_places\"}",
            '{"business_name": "Broken "quote" Bakery", "place_id": "places/Y", "source": "google_places"}',
            $this->googleRow('places/Z', ['place_id' => null]),
            $this->googleRow('node/5'),
            $this->osmRow('places/Q'),
            $this->googleRow('places/T', ['latitude' => 35.6762, 'longitude' => 139.6503]),
            $this->googleRow('places/S', ['source' => 'yelp']),
            '[1,2,3]',
        ]);

        $dry = $this->import($path)['manifest'];
        $rejected = $dry['reasons']['rejected'];

        $this->assertSame([2], $rejected['invalid_utf8']['lines']);
        $this->assertSame([3], $rejected['invalid_json']['lines']);
        $this->assertSame([4], $rejected['invalid_field:place_id']['lines']);
        $this->assertSame([5, 6], $rejected['place_id_source_mismatch']['lines']);
        $this->assertSame([7], $rejected['outside_philippines']['lines']);
        $this->assertSame([8], $rejected['invalid_field:source']['lines']);
        $this->assertSame([9], $rejected['not_an_object']['lines']);
        $this->assertSame(8, $dry['counts']['rejected']);
        $this->assertSame(1, $dry['counts']['eligible']);

        $write = $this->confirmed($path);
        $this->assertSame(1, $write['exit']);
        $this->assertSame('refused', $write['manifest']['status']);
        $this->assertSame(0, Lead::query()->count());
    }
}
