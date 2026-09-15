<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Jobs\ConvertXlsImportJob;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Import\XlsImportJobStore;
use App\Modules\Crm\Services\LeadImportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls as XlsWriter;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

/**
 * CEO audit crm-06(ii) — the two approved import limits.
 *
 *  1. ROW CAP. An import may carry at most {@see LeadImportService::MAX_IMPORT_ROWS} data rows. The cap is
 *     enforced while the file is read, so an over-cap file is refused with 422 on every path (CSV/TXT,
 *     XLSX, a small .xls, the queued large-.xls job and its commit) before anything is written.
 *  2. IMPORT THROTTLE. POST /leads/import allows 10 starts per minute per user, in its own bucket. Status
 *     polling, discovered-lead ingest and every other CRM throttle are untouched.
 *
 * Everything else about importing — mapping, dedup, restore, assignment, notes, scoring, the organization
 * commit lock and its 409 — is unchanged and covered by the existing import suites.
 */
class LeadImportLimitsTest extends TestCase
{
    use RefreshDatabase;

    private const OVER_CAP_MESSAGE = 'This file has more than 5,000 rows. Split it into smaller files.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/xls-imports/*')) ?: [] as $leftover) {
            @unlink($leftover);
        }

        parent::tearDown();
    }

    private function bd(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('crm_bd');

        return $user;
    }

    /** @return list<list<string>> header + `$count` data rows */
    private function rows(int $count, string $prefix = 'Cap'): array
    {
        $rows = [['Business Name', 'Contact Name']];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = ["{$prefix} Business {$i}", "Owner {$i}"];
        }

        return $rows;
    }

    /** @param list<list<string>> $rows */
    private function csv(array $rows, string $name = 'leads.csv', string $trailer = ''): UploadedFile
    {
        $content = implode("\n", array_map(fn (array $r) => implode(',', $r), $rows))."\n".$trailer;

        return UploadedFile::fake()->createWithContent($name, $content);
    }

    /** @param list<list<string>> $rows */
    private function spreadsheet(array $rows): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray($rows);

        return $book;
    }

    /** @param list<list<string>> $rows */
    private function xlsFile(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'cap').'.xls';
        (new XlsWriter($this->spreadsheet($rows)))->save($path);

        return new UploadedFile($path, 'leads.xls', 'application/vnd.ms-excel', null, true);
    }

    private function import(User $user, array $data): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->post('/api/v1/crm/leads/import', $data, ['Accept' => 'application/json']);
    }

    private function assertRejectedOverCap(TestResponse $response): void
    {
        $response->assertStatus(422)
            ->assertJsonPath('message', self::OVER_CAP_MESSAGE)
            ->assertJsonPath('errors.file.0', self::OVER_CAP_MESSAGE);
    }

    private function assertNothingWritten(): void
    {
        $this->assertSame(0, Lead::withTrashed()->count(), 'an over-cap import must not create leads');
        $this->assertSame(0, Activity::query()->count(), 'an over-cap import must not log activities');
        $this->assertSame(0, DB::table('crm_notes')->count(), 'an over-cap import must not create notes');
    }

    // ── 1. Row cap ─────────────────────────────────────────────────────────────────────────────

    public function test_the_cap_is_five_thousand_rows(): void
    {
        $this->assertSame(5000, LeadImportService::MAX_IMPORT_ROWS);
    }

    public function test_a_file_of_exactly_five_thousand_rows_is_previewed_and_imported(): void
    {
        $bd = $this->bd();
        $rows = $this->rows(5000);

        $this->import($bd, ['file' => $this->csv($rows), 'dry_run' => 'true'])
            ->assertOk()
            ->assertJsonPath('data.summary.total', 5000)
            ->assertJsonPath('data.summary.valid', 5000);

        $this->import($bd, ['file' => $this->csv($rows), 'dry_run' => 'false'])
            ->assertOk()
            ->assertJsonPath('data.importedCount', 5000);

        $this->assertSame(5000, Lead::query()->where('assigned_to', $bd->id)->count());
    }

    public function test_five_thousand_and_one_rows_are_rejected_on_preview(): void
    {
        $bd = $this->bd();

        $this->assertRejectedOverCap($this->import($bd, ['file' => $this->csv($this->rows(5001)), 'dry_run' => 'true']));

        $this->assertNothingWritten();
    }

    public function test_five_thousand_and_one_rows_are_rejected_on_commit_and_nothing_is_written(): void
    {
        $bd = $this->bd();

        $this->assertRejectedOverCap($this->import($bd, ['file' => $this->csv($this->rows(5001)), 'dry_run' => 'false']));

        $this->assertNothingWritten();
    }

    public function test_the_cap_applies_to_txt_uploads_too(): void
    {
        $bd = $this->bd();

        $this->assertRejectedOverCap($this->import($bd, ['file' => $this->csv($this->rows(5001), 'leads.txt'), 'dry_run' => 'true']));

        $this->assertNothingWritten();
    }

    public function test_the_cap_applies_to_xlsx_uploads(): void
    {
        $bd = $this->bd();
        $path = tempnam(sys_get_temp_dir(), 'cap').'.xlsx';
        (new XlsxWriter($this->spreadsheet($this->rows(5001))))->save($path);
        $file = new UploadedFile($path, 'leads.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->assertRejectedOverCap($this->import($bd, ['file' => $file, 'dry_run' => 'false']));

        $this->assertNothingWritten();
    }

    /** The header row and wholly blank rows are not data: 5,000 real rows plus blank lines still fit. */
    public function test_blank_rows_do_not_count_toward_the_cap(): void
    {
        $bd = $this->bd();

        $this->import($bd, ['file' => $this->csv($this->rows(5000), 'leads.csv', ",\n,\n\n,\n"), 'dry_run' => 'true'])
            ->assertOk()
            ->assertJsonPath('data.summary.total', 5000);
    }

    public function test_an_over_cap_small_xls_is_rejected_on_the_synchronous_path(): void
    {
        $bd = $this->bd();

        $this->assertRejectedOverCap($this->import($bd, ['file' => $this->xlsFile($this->rows(5001)), 'dry_run' => 'false']));

        $this->assertNothingWritten();
    }

    /**
     * The queued large-.xls path: the conversion job's preview hits the cap, the job is recorded as failed
     * with the same message (which the status poll hands to the modal), and it can never be committed.
     */
    public function test_an_over_cap_xls_fails_its_queued_preview_with_the_cap_message_and_cannot_be_committed(): void
    {
        $bd = $this->bd();
        $store = app(XlsImportJobStore::class);
        $jobId = $store->create($bd->id, 'big.xls');
        $source = $store->storagePathFor($jobId).'.source.xls';
        @mkdir(dirname($source), 0775, true);
        (new XlsWriter($this->spreadsheet($this->rows(5001))))->save($source);
        $store->attachSource($jobId, $source);

        ConvertXlsImportJob::dispatchSync($jobId, $source, 'big.xls', $bd->id, $bd->id);

        Sanctum::actingAs($bd);
        $this->getJson("/api/v1/crm/leads/import/{$jobId}/status")
            ->assertOk()
            ->assertJsonPath('data.status', XlsImportJobStore::STATUS_FAILED)
            ->assertJsonPath('data.error', self::OVER_CAP_MESSAGE);

        $this->import($bd, ['job_id' => $jobId, 'dry_run' => 'false'])->assertStatus(422);

        $this->assertNothingWritten();
    }

    /** Even a job record marked ready is re-read on commit, so an over-cap converted file is still refused. */
    public function test_an_over_cap_file_is_refused_on_the_job_commit_path(): void
    {
        $bd = $this->bd();
        $store = app(XlsImportJobStore::class);
        $jobId = $store->create($bd->id, 'big.xls');
        $converted = $store->storagePathFor($jobId);
        (new XlsxWriter($this->spreadsheet($this->rows(5001))))->save($converted);
        $store->markReady($jobId, $converted, ['summary' => ['total' => 5001]]);

        $this->assertRejectedOverCap($this->import($bd, ['job_id' => $jobId, 'dry_run' => 'false']));

        $this->assertNothingWritten();
    }

    // ── 2. Import throttle ─────────────────────────────────────────────────────────────────────

    private function tinyCsv(string $prefix): UploadedFile
    {
        return $this->csv($this->rows(1, $prefix));
    }

    /** @return list<int> */
    private function startImports(User $user, int $times, string $prefix = 'Throttle'): array
    {
        $statuses = [];
        for ($i = 0; $i < $times; $i++) {
            $statuses[] = $this->import($user, ['file' => $this->tinyCsv("{$prefix} {$i}"), 'dry_run' => 'true'])->status();
        }

        return $statuses;
    }

    public function test_the_eleventh_import_start_within_a_minute_is_refused_with_429(): void
    {
        $bd = $this->bd();

        $this->assertSame(array_fill(0, 10, 200), $this->startImports($bd, 10));
        $this->import($bd, ['file' => $this->tinyCsv('Eleventh'), 'dry_run' => 'false'])->assertStatus(429);

        $this->assertSame(0, Lead::query()->count(), 'a throttled commit writes nothing');
    }

    public function test_the_import_limit_resets_after_a_minute(): void
    {
        $bd = $this->bd();
        $this->startImports($bd, 10);
        $this->import($bd, ['file' => $this->tinyCsv('Blocked'), 'dry_run' => 'true'])->assertStatus(429);

        $this->travel(61)->seconds();

        $this->import($bd, ['file' => $this->tinyCsv('Allowed again'), 'dry_run' => 'true'])->assertOk();
    }

    public function test_each_user_has_an_independent_import_bucket(): void
    {
        $first = $this->bd();
        $second = $this->bd();

        $this->startImports($first, 10);
        $this->import($first, ['file' => $this->tinyCsv('First blocked'), 'dry_run' => 'true'])->assertStatus(429);

        $this->assertSame(array_fill(0, 10, 200), $this->startImports($second, 10, 'Second'));
        $this->import($second, ['file' => $this->tinyCsv('Second blocked'), 'dry_run' => 'true'])->assertStatus(429);
    }

    public function test_import_status_polling_is_not_throttled(): void
    {
        $bd = $this->bd();
        $store = app(XlsImportJobStore::class);
        $jobId = $store->create($bd->id, 'leads.xls');
        $store->markReady($jobId, $store->storagePathFor($jobId), ['summary' => ['total' => 1]]);

        $this->startImports($bd, 10);
        $this->import($bd, ['file' => $this->tinyCsv('Blocked'), 'dry_run' => 'true'])->assertStatus(429);

        Sanctum::actingAs($bd);
        for ($i = 0; $i < 30; $i++) {
            $this->getJson("/api/v1/crm/leads/import/{$jobId}/status")->assertOk();
        }
    }

    public function test_discovered_lead_ingest_is_not_throttled(): void
    {
        $bd = $this->bd();
        $this->startImports($bd, 10);
        $this->import($bd, ['file' => $this->tinyCsv('Blocked'), 'dry_run' => 'true'])->assertStatus(429);

        Sanctum::actingAs($bd);
        for ($i = 0; $i < 15; $i++) {
            // An empty payload is refused by validation (422) — never by a throttle.
            $status = $this->postJson('/api/v1/crm/leads/discovered', ['dry_run' => true])->status();
            $this->assertNotSame(429, $status, "discovered-lead ingest request {$i} was throttled");
        }
    }

    /** The import bucket and an existing CRM throttle (profile photo upload, `throttle:10,1`) never share a counter. */
    public function test_the_import_bucket_does_not_share_state_with_other_crm_throttles(): void
    {
        $bd = $this->bd();
        Sanctum::actingAs($bd);

        // Exhaust the profile-photo throttle (each request fails validation, but still counts).
        for ($i = 0; $i < 10; $i++) {
            $this->assertNotSame(429, $this->postJson('/api/v1/crm/me/photo', [])->status());
        }
        $this->postJson('/api/v1/crm/me/photo', [])->assertStatus(429);

        // ...the import bucket is untouched and still allows all 10 starts.
        $this->assertSame(array_fill(0, 10, 200), $this->startImports($bd, 10));
        $this->import($bd, ['file' => $this->tinyCsv('Blocked'), 'dry_run' => 'true'])->assertStatus(429);

        // And the other way round: a user whose import bucket is exhausted can still use the photo route.
        $other = $this->bd();
        $this->startImports($other, 10, 'Other');
        $this->import($other, ['file' => $this->tinyCsv('Other blocked'), 'dry_run' => 'true'])->assertStatus(429);
        Sanctum::actingAs($other);
        $this->assertNotSame(429, $this->postJson('/api/v1/crm/me/photo', [])->status());
    }
}
