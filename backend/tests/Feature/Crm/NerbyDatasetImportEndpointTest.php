<?php

namespace Tests\Feature\Crm;

use App\Models\Org;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\Crm\Jobs\ImportNerbyDatasetJob;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadWebsiteCandidate;
use App\Modules\Crm\Services\Discovery\NerbyServiceIdentity;
use App\Support\CurrentOrg;
use App\Support\Entitlements;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Settings → NERBY → Dataset Import — the HTTP wrapper around NerbyDatasetImporter.
 *
 * The engine's own rules are pinned by NerbyDatasetImportTest. These tests pin what the wrapper adds:
 * who may use it (Super Admin and CRM Manager, never a BD), that the dry run writes nothing, that a
 * write needs the reviewed file's exact SHA-256 and runs on the queue, that it lands only in the
 * caller's own org with the NERBY service account as creator and the admin in the audit manifest,
 * that only Google Places is offered, and that no server path leaks to the browser.
 */
class NerbyDatasetImportEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/crm/settings/nerby/dataset-import';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');

        // NO PROVIDER IS CALLED: any outbound HTTP request fails the test.
        Http::preventStrayRequests();
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);

        return $user;
    }

    private function row(string $placeId, array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Bakery '.$placeId,
            'contact_name' => 'Bakery '.$placeId,
            'phone' => '0917 000 0000',
            'industry' => 'Bakery',
            'region' => 'Western Visayas',
            'province' => 'Negros Occidental',
            'city' => 'Bacolod',
            'latitude' => 10.6729471,
            'longitude' => 122.9456394,
            'place_id' => $placeId,
            'source' => 'google_places',
            'website' => null,
            'facebook' => null,
            'instagram' => null,
            'discovery_ai_assisted' => null,
        ], $overrides);
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function dataset(array $rows, string $name = 'google-places-ph-final.jsonl'): UploadedFile
    {
        $content = implode("\n", array_map(fn (array $r): string => json_encode($r, JSON_UNESCAPED_SLASHES), $rows))."\n";

        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function sha(UploadedFile $file): string
    {
        return hash_file('sha256', (string) $file->getRealPath());
    }

    private function dryRun(UploadedFile $file, string $source = 'google_places'): TestResponse
    {
        return $this->post(self::BASE.'/dry-run', ['file' => $file, 'source' => $source], ['Accept' => 'application/json']);
    }

    private function import(UploadedFile $file, ?string $sha = null, string $source = 'google_places'): TestResponse
    {
        return $this->post(self::BASE, [
            'file' => $file, 'source' => $source, 'confirm_sha256' => $sha ?? $this->sha($file),
        ], ['Accept' => 'application/json']);
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

    private function creator(): User
    {
        return User::query()->where('email', 'existing-owner@test.local')->first()
            ?? User::factory()->create(['email' => 'existing-owner@test.local']);
    }

    // ── access ────────────────────────────────────────────────────────────────────────────────

    public function test_a_business_developer_is_refused_on_every_endpoint(): void
    {
        Sanctum::actingAs($this->userWithRole('crm_bd'));
        Queue::fake();

        $this->dryRun($this->dataset([$this->row('places/A')]))->assertForbidden();
        $this->import($this->dataset([$this->row('places/A')]))->assertForbidden();
        $this->getJson(self::BASE.'/'.Str::uuid())->assertForbidden();

        Queue::assertNotPushed(ImportNerbyDatasetJob::class);
        $this->assertSame(0, Lead::query()->count());
    }

    public function test_a_guest_is_refused(): void
    {
        $this->dryRun($this->dataset([$this->row('places/A')]))->assertUnauthorized();
    }

    public function test_a_crm_manager_and_a_super_admin_may_dry_run(): void
    {
        foreach (['crm_admin', 'super_admin'] as $role) {
            Sanctum::actingAs($this->userWithRole($role));
            $this->dryRun($this->dataset([$this->row('places/A')]))->assertOk();
        }
    }

    // ── dry run ───────────────────────────────────────────────────────────────────────────────

    public function test_the_dry_run_reports_and_writes_nothing(): void
    {
        $admin = $this->userWithRole('crm_admin');
        Sanctum::actingAs($admin);
        Queue::fake();
        $file = $this->dataset([$this->row('places/A'), $this->row('places/B')]);

        $response = $this->dryRun($file)->assertOk();

        $response->assertJsonPath('data.mode', 'dry_run')
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.file.name', 'google-places-ph-final.jsonl')
            ->assertJsonPath('data.file.sha256', $this->sha($file))
            ->assertJsonPath('data.counts.eligible', 2)
            ->assertJsonPath('data.counts.imported', 0)
            ->assertJsonPath('data.org.id', $this->tenant()->id)
            ->assertJsonPath('data.org.name', $this->tenant()->name)
            ->assertJsonPath('data.requested_by.id', $admin->id)
            ->assertJsonPath('data.sources', ['google_places']);

        $this->assertSame(0, Lead::query()->count());
        Queue::assertNotPushed(ImportNerbyDatasetJob::class);
        // A dry run must not even provision the service account.
        $this->assertNull(User::query()->where('email', config('discovery.service_account.email', 'nerby-service@crm.local'))->first());
    }

    public function test_no_server_path_reaches_the_browser(): void
    {
        Sanctum::actingAs($this->userWithRole('crm_admin'));

        $body = $this->dryRun($this->dataset([$this->row('places/A')]))->assertOk()->getContent();

        $this->assertStringNotContainsString('"path"', (string) $body);
        $this->assertStringNotContainsString(sys_get_temp_dir(), (string) $body);
        $this->assertStringNotContainsString('actor_id', (string) $body);
    }

    public function test_the_dry_run_is_kept_as_an_audit_manifest_naming_the_requester(): void
    {
        $admin = $this->userWithRole('crm_admin');
        Sanctum::actingAs($admin);

        $this->dryRun($this->dataset([$this->row('places/A')]))->assertOk();

        $files = Storage::disk('local')->files('nerby-imports');
        $this->assertCount(1, $files);
        $manifest = json_decode((string) Storage::disk('local')->get($files[0]), true);
        $this->assertSame($admin->id, $manifest['requested_by']['id']);
        $this->assertSame('settings', $manifest['via']);
        $this->assertSame('dry_run', $manifest['mode']);
    }

    // ── what the screen accepts ───────────────────────────────────────────────────────────────

    public function test_only_google_places_is_offered_as_a_source(): void
    {
        Sanctum::actingAs($this->userWithRole('crm_admin'));

        foreach (['openstreetmap', 'openai', 'discovery_ai_assisted'] as $source) {
            $this->dryRun($this->dataset([$this->row('node/1', ['source' => 'openstreetmap'])]), $source)
                ->assertUnprocessable()->assertJsonValidationErrors('source');
        }
    }

    public function test_the_upload_must_be_a_jsonl_file_of_sane_size(): void
    {
        Sanctum::actingAs($this->userWithRole('crm_admin'));

        $this->dryRun(UploadedFile::fake()->createWithContent('leads.csv', "a,b\n"))
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->dryRun(UploadedFile::fake()->create('huge.jsonl', 10241))
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->dryRun($this->dataset([$this->row('places/A')], 'dataset.ndjson'))->assertOk();
    }

    public function test_an_empty_file_is_refused_and_never_reported_as_already_in_the_crm(): void
    {
        Sanctum::actingAs($this->userWithRole('crm_admin'));
        Queue::fake();
        $empty = fn (): UploadedFile => UploadedFile::fake()->createWithContent('google-places-ph-final.jsonl', '');

        $this->dryRun($empty())->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'The file is empty.'])
            ->assertJsonMissingPath('data');
        $this->import($empty(), hash('sha256', ''))->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'The file is empty.']);

        Queue::assertNotPushed(ImportNerbyDatasetJob::class);
        $this->assertSame([], Storage::disk('local')->allFiles('nerby-imports'));
    }

    public function test_a_file_php_refused_as_too_large_gets_a_clear_message(): void
    {
        Sanctum::actingAs($this->userWithRole('crm_admin'));
        $tmp = tempnam(sys_get_temp_dir(), 'nerby');
        file_put_contents($tmp, '');
        // What PHP hands Laravel when the file exceeds `upload_max_filesize`: a name, no bytes, an error.
        $refused = new UploadedFile($tmp, 'google-places-ph-final.jsonl', null, UPLOAD_ERR_INI_SIZE, true);

        $message = $this->dryRun($refused)->assertUnprocessable()->assertJsonValidationErrors('file')->json('errors.file.0');

        $this->assertStringContainsString('larger than this server accepts (limit:', $message);
        $this->assertStringContainsString('upload limit must be raised', $message);
        $this->assertSame([], Storage::disk('local')->allFiles('nerby-imports'));
        @unlink($tmp);
    }

    // ── confirmation ──────────────────────────────────────────────────────────────────────────

    public function test_an_import_is_refused_unless_the_sha256_is_the_reviewed_files(): void
    {
        Sanctum::actingAs($this->userWithRole('crm_admin'));
        Queue::fake();

        $this->import($this->dataset([$this->row('places/A')]), str_repeat('a', 64))
            ->assertUnprocessable()->assertJsonValidationErrors('confirm_sha256');
        $this->post(self::BASE, ['file' => $this->dataset([$this->row('places/A')]), 'source' => 'google_places'], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('confirm_sha256');

        Queue::assertNotPushed(ImportNerbyDatasetJob::class);
        $this->assertSame([], Storage::disk('local')->allFiles('nerby-imports/uploads'));
        $this->assertSame(0, Lead::query()->count());
    }

    public function test_a_confirmed_import_is_queued_for_the_callers_org(): void
    {
        $admin = $this->userWithRole('crm_admin');
        Sanctum::actingAs($admin);
        Queue::fake();
        $file = $this->dataset([$this->row('places/A')]);

        $response = $this->import($file)->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.sha256', $this->sha($file))
            ->assertJsonPath('data.manifest', null);

        Queue::assertPushed(ImportNerbyDatasetJob::class, fn (ImportNerbyDatasetJob $job): bool => $job->orgId === $this->tenant()->id
            && $job->requestedBy === $admin->id
            && $job->sources === ['google_places']
            && $job->confirmSha256 === $this->sha($file)
            && $job->jobId === $response->json('data.job_id'));
        $this->assertSame(0, Lead::query()->count());
    }

    // ── the write, end to end (sync queue) ─────────────────────────────────────────────────────

    public function test_the_import_lands_new_businesses_and_never_touches_existing_ones(): void
    {
        $admin = $this->userWithRole('crm_admin');
        $existing = Lead::factory()->create([
            'created_by' => $this->creator()->id, 'place_id' => 'places/OLD',
            'source' => 'google_places', 'website' => 'https://old.example',
        ]);
        $before = DB::table('crm_leads')->where('id', $existing->id)->first();
        $deleted = Lead::factory()->create(['created_by' => $this->creator()->id, 'place_id' => 'places/GONE', 'source' => 'google_places']);
        $deleted->delete();

        Sanctum::actingAs($admin);
        $response = $this->import($this->dataset([
            $this->row('places/NEW', ['facebook' => 'https://facebook.com/new-bakery', 'instagram' => 'https://instagram.com/newbakery']),
            $this->row('places/OLD', ['business_name' => 'Renamed', 'website' => 'https://new.example']),
            $this->row('places/GONE'),
        ]))->assertStatus(202);

        $status = $this->getJson(self::BASE.'/'.$response->json('data.job_id'))->assertOk();
        $status->assertJsonPath('data.status', 'finished')
            ->assertJsonPath('data.manifest.mode', 'write')
            ->assertJsonPath('data.manifest.status', 'ok')
            ->assertJsonPath('data.manifest.counts.imported', 1)
            ->assertJsonPath('data.manifest.reasons.skipped.existing_place_id.count', 1)
            ->assertJsonPath('data.manifest.reasons.skipped.existing_place_id_soft_deleted.count', 1)
            ->assertJsonPath('data.manifest.requested_by.id', $admin->id);

        $lead = Lead::query()->where('place_id', 'places/NEW')->sole();
        $this->assertSame('google_places', $lead->source);
        $this->assertSame(10.6729471, (float) $lead->latitude);
        $this->assertSame(122.9456394, (float) $lead->longitude);
        $this->assertSame('https://facebook.com/new-bakery', $lead->facebook);
        $this->assertSame('https://instagram.com/newbakery', $lead->instagram);
        $this->assertSame(app(NerbyServiceIdentity::class)->user()->id, (int) $lead->created_by);
        $this->assertNull($lead->assigned_to);
        $this->assertSame($this->tenant()->id, (int) $lead->org_id);

        $this->assertEquals($before, DB::table('crm_leads')->where('id', $existing->id)->first());
        $this->assertTrue(Lead::withTrashed()->findOrFail($deleted->id)->trashed());
        $this->assertSame(1, Lead::query()->where('place_id', 'places/GONE')->withTrashed()->count());
        $this->assertSame(0, LeadWebsiteCandidate::query()->count());
        $this->assertSame(0, DB::table('crm_lead_discovery_lanes')->count());

        // The parked upload is gone; the write manifest is kept and names the admin.
        $this->assertSame([], Storage::disk('local')->allFiles('nerby-imports/uploads'));
        $writes = array_values(array_filter(Storage::disk('local')->files('nerby-imports'), fn (string $f): bool => str_contains($f, '-write-')));
        $this->assertCount(1, $writes);
        $this->assertSame($admin->id, json_decode((string) Storage::disk('local')->get($writes[0]), true)['requested_by']['id']);
    }

    public function test_a_crm_manager_imports_into_their_own_org_only(): void
    {
        $other = $this->otherOrg();
        // The tenant org already has this place; that must not stop the other org from importing it.
        Lead::factory()->create(['created_by' => $this->creator()->id, 'place_id' => 'places/A', 'source' => 'google_places']);

        $admin = $this->userWithRole('crm_admin');
        $admin->orgs()->sync([$other->id]);
        Sanctum::actingAs($admin);

        $this->import($this->dataset([$this->row('places/A')]))->assertStatus(202);

        $this->assertSame(1, CurrentOrg::runAs($other->id, fn (): int => Lead::query()->where('place_id', 'places/A')->count()));
        $this->assertSame(1, CurrentOrg::runAs($this->tenant()->id, fn (): int => Lead::query()->where('place_id', 'places/A')->count()));
    }

    // ── status polling ────────────────────────────────────────────────────────────────────────

    public function test_a_job_is_visible_only_to_the_admin_who_started_it(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->userWithRole('crm_admin'));
        $jobId = $this->import($this->dataset([$this->row('places/A')]))->assertStatus(202)->json('data.job_id');

        $this->getJson(self::BASE.'/'.$jobId)->assertOk()->assertJsonPath('data.status', 'queued');

        Sanctum::actingAs($this->userWithRole('crm_admin'));
        $this->getJson(self::BASE.'/'.$jobId)->assertNotFound();
        $this->getJson(self::BASE.'/'.Str::uuid())->assertNotFound();
    }
}
