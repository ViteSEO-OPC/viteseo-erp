<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Jobs\ConvertXlsImportJob;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Import\XlsConverter;
use App\Modules\Crm\Services\Import\XlsImportJobStore;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls as XlsWriter;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

/**
 * Legacy `.xls` import — synchronous path, queued path, security and cleanup.
 *
 * The design under test: `.xls` is normalized to `.xlsx` and then read by the EXISTING OpenSpout
 * pipeline, so header aliasing, value translation, deduplication, RBAC and assignment cannot observe
 * which format the rows arrived in. Large files are ROUTED to the queue, never rejected — a valid
 * `.xls` up to 10 MB must actually import.
 */
class LeadImportXlsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * The queued-routing test uses Queue::fake(), so no job ever runs to delete the source file the
     * controller parked on disk. Production cleans it via XlsImportJobStore (commit / failure /
     * expiry); the faked queue has none of those, so the test owns its own cleanup.
     */
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

    private function admin(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('crm_admin');

        return $user;
    }

    /** @param list<list<string>> $rows */
    private function spreadsheet(array $rows): Spreadsheet
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        foreach ($rows as $r => $cells) {
            foreach ($cells as $c => $value) {
                $sheet->setCellValue([$c + 1, $r + 1], $value);
            }
        }

        return $book;
    }

    /** @param list<list<string>> $rows */
    private function xlsFile(array $rows, string $name = 'leads.xls'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'test-xls-').'.xls';
        (new XlsWriter($this->spreadsheet($rows)))->save($path);

        return new UploadedFile($path, $name, null, null, true);
    }

    /** @param list<list<string>> $rows */
    private function xlsxFile(array $rows, string $name = 'leads.xlsx'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'test-xlsx-').'.xlsx';
        (new XlsxWriter($this->spreadsheet($rows)))->save($path);

        return new UploadedFile($path, $name, null, null, true);
    }

    /** The real sheet's header shape, including the apostrophe and the enum columns. */
    private function sampleRows(): array
    {
        return [
            ['Business Name', "Owner's Name", 'Email', 'Contact Number', 'Niche', 'Cold Calling'],
            ['Acme Dental', 'Maria Santos', 'maria@acme.ph', '0917 555 0101', 'Healthcare', 'Completed'],
            ['Bright Future', 'Jose Cruz', 'jose@bright.ph', '0918 555 0202', 'Education', 'Not Started'],
        ];
    }

    private function import(User $as, array $params): TestResponse
    {
        Sanctum::actingAs($as);

        return $this->post('/api/v1/crm/leads/import', $params);
    }

    // ───────────────────────────────────────────────────────── synchronous path

    public function test_a_small_xls_imports_through_the_synchronous_path(): void
    {
        $bd = $this->bd();

        $preview = $this->import($bd, ['file' => $this->xlsFile($this->sampleRows()), 'dry_run' => 'true'])
            ->assertOk()->json('data');

        $this->assertSame(2, $preview['summary']['total']);
        $this->assertSame(2, $preview['summary']['valid']);
        $this->assertCount(0, $preview['errors']);

        $this->import($bd, ['file' => $this->xlsFile($this->sampleRows()), 'dry_run' => 'false'])
            ->assertOk();

        $this->assertDatabaseHas('crm_leads', ['business_name' => 'Acme Dental', 'assigned_to' => $bd->id]);
    }

    public function test_xls_and_xlsx_produce_identical_results(): void
    {
        $bdA = $this->bd();
        $bdB = $this->bd();

        $fromXls = $this->import($bdA, ['file' => $this->xlsFile($this->sampleRows()), 'dry_run' => 'true'])
            ->assertOk()->json('data');
        $fromXlsx = $this->import($bdB, ['file' => $this->xlsxFile($this->sampleRows()), 'dry_run' => 'true'])
            ->assertOk()->json('data');

        // The whole point of converting rather than writing a second parser: identical summary,
        // identical errors, identical header mapping.
        $this->assertSame($fromXlsx['summary'], $fromXls['summary']);
        $this->assertSame($fromXlsx['errors'], $fromXls['errors']);
        $this->assertSame($fromXlsx['mapping'], $fromXls['mapping']);
        $this->assertSame($fromXlsx['unknownColumns'], $fromXls['unknownColumns']);
    }

    public function test_aliases_and_translation_apply_to_xls_exactly_as_to_xlsx(): void
    {
        $bd = $this->bd();

        $this->import($bd, ['file' => $this->xlsFile($this->sampleRows()), 'dry_run' => 'false'])->assertOk();

        $lead = Lead::where('business_name', 'Acme Dental')->sole();
        $this->assertSame('Maria Santos', $lead->contact_name);   // Owner's Name → contact_name
        $this->assertSame('0917 555 0101', $lead->phone);          // Contact Number → phone
        $this->assertSame('Healthcare', $lead->industry);          // Niche → industry
        $this->assertSame('called', $lead->cold_calling);          // "Completed" → called
    }

    public function test_a_malformed_xls_is_rejected_with_422_not_500(): void
    {
        $bd = $this->bd();
        $path = tempnam(sys_get_temp_dir(), 'broken-').'.xls';
        file_put_contents($path, 'this is definitely not a BIFF workbook');

        $this->import($bd, [
            'file' => new UploadedFile($path, 'broken.xls', null, null, true),
            'dry_run' => 'true',
        ])->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_conversion_leaves_no_temporary_files_behind(): void
    {
        $bd = $this->bd();
        $before = glob(sys_get_temp_dir().'/crm-xls-*') ?: [];

        $this->import($bd, ['file' => $this->xlsFile($this->sampleRows()), 'dry_run' => 'true'])->assertOk();

        $this->assertSame($before, glob(sys_get_temp_dir().'/crm-xls-*') ?: []);
    }

    public function test_a_malformed_xls_also_leaves_no_temporary_files(): void
    {
        $bd = $this->bd();
        $before = glob(sys_get_temp_dir().'/crm-xls-*') ?: [];
        $path = tempnam(sys_get_temp_dir(), 'broken-').'.xls';
        file_put_contents($path, 'not a workbook');

        $this->import($bd, [
            'file' => new UploadedFile($path, 'broken.xls', null, null, true),
            'dry_run' => 'true',
        ])->assertStatus(422);

        $this->assertSame($before, glob(sys_get_temp_dir().'/crm-xls-*') ?: []);
    }

    // ───────────────────────────────────────────────────────── routing to the queue

    public function test_a_large_xls_is_queued_and_never_rejected(): void
    {
        Queue::fake();
        $bd = $this->bd();

        // Cheap synthetic file over the routing threshold: the guard reads DIMENSIONS, so a wide
        // sparse sheet trips it without generating 150K real cells.
        $rows = [array_merge(['Business Name', "Owner's Name"], array_fill(0, 60, 'x'))];
        for ($i = 0; $i < 2600; $i++) {
            $rows[] = array_merge(["Biz {$i}", "Owner {$i}"], array_fill(0, 60, 'v'));
        }

        $response = $this->import($bd, ['file' => $this->xlsFile($rows, 'big.xls'), 'dry_run' => 'true']);

        $response->assertStatus(202)->assertJsonPath('data.status', XlsImportJobStore::STATUS_PENDING);
        $this->assertNotEmpty($response->json('data.jobId'));
        Queue::assertPushed(ConvertXlsImportJob::class);

        // The parked upload must be RECORDED, so cleanup can find it even if the job never runs
        // (worker outage, lost job). Without this the moved file would leak with nothing pointing
        // at it — which is exactly what the first suite run left behind on disk.
        $record = app(XlsImportJobStore::class)->forUser($response->json('data.jobId'), $bd->id);
        $this->assertNotNull($record['source_path']);
        $this->assertFileExists($record['source_path']);

        app(XlsImportJobStore::class)->forget($response->json('data.jobId'));
        $this->assertFileDoesNotExist($record['source_path']);
    }

    public function test_the_routing_threshold_is_measured_without_loading_the_workbook(): void
    {
        $rows = [['Business Name', "Owner's Name"], ['Acme', 'Maria']];
        $file = $this->xlsFile($rows);

        $cells = app(XlsConverter::class)->estimateCells((string) $file->getRealPath());

        $this->assertGreaterThan(0, $cells);
        $this->assertLessThan(XlsConverter::SYNC_CELL_THRESHOLD, $cells);
    }

    // ───────────────────────────────────────────────────────── job security & lifecycle

    public function test_a_job_belonging_to_another_user_is_not_found(): void
    {
        $owner = $this->bd();
        $other = $this->bd();
        $jobId = app(XlsImportJobStore::class)->create($owner->id, 'leads.xls');

        Sanctum::actingAs($other);
        $this->getJson("/api/v1/crm/leads/import/{$jobId}/status")->assertStatus(404);

        // 404 not 403 — a foreign job must be indistinguishable from a missing one.
        $this->post('/api/v1/crm/leads/import', ['job_id' => $jobId, 'dry_run' => 'false'])
            ->assertStatus(404);
    }

    public function test_a_job_cannot_be_committed_twice(): void
    {
        $bd = $this->bd();
        $store = app(XlsImportJobStore::class);
        $jobId = $store->create($bd->id, 'leads.xls');

        $converted = $store->storagePathFor($jobId);
        (new XlsxWriter($this->spreadsheet($this->sampleRows())))->save($converted);
        $store->markReady($jobId, $converted, ['total' => 2]);

        $this->import($bd, ['job_id' => $jobId, 'dry_run' => 'false'])->assertOk();
        $this->assertDatabaseHas('crm_leads', ['business_name' => 'Acme Dental', 'assigned_to' => $bd->id]);

        // Second confirm must not import again. The record is deleted on successful commit, so a
        // sequential replay is 404; a CONCURRENT replay loses the markCommitted() compare-and-set
        // and is 422. Both are refusals — what must hold is that nothing imported twice.
        $replay = $this->import($bd, ['job_id' => $jobId, 'dry_run' => 'false']);
        $this->assertContains($replay->getStatusCode(), [404, 422]);
        $this->assertSame(1, Lead::where('business_name', 'Acme Dental')->count());
    }

    public function test_the_converted_file_is_deleted_after_a_successful_commit(): void
    {
        $bd = $this->bd();
        $store = app(XlsImportJobStore::class);
        $jobId = $store->create($bd->id, 'leads.xls');

        $converted = $store->storagePathFor($jobId);
        (new XlsxWriter($this->spreadsheet($this->sampleRows())))->save($converted);
        $store->markReady($jobId, $converted, ['total' => 2]);
        $this->assertFileExists($converted);

        $this->import($bd, ['job_id' => $jobId, 'dry_run' => 'false'])->assertOk();

        $this->assertFileDoesNotExist($converted);
        $this->assertNull($store->forUser($jobId, $bd->id));
    }

    public function test_an_unready_job_cannot_be_committed(): void
    {
        $bd = $this->bd();
        $jobId = app(XlsImportJobStore::class)->create($bd->id, 'leads.xls');

        $this->import($bd, ['job_id' => $jobId, 'dry_run' => 'false'])->assertStatus(422);
    }

    public function test_the_status_endpoint_returns_the_stored_preview_when_ready(): void
    {
        $bd = $this->bd();
        $store = app(XlsImportJobStore::class);
        $jobId = $store->create($bd->id, 'leads.xls');
        $store->markReady($jobId, $store->storagePathFor($jobId), ['total' => 7]);

        Sanctum::actingAs($bd);
        $this->getJson("/api/v1/crm/leads/import/{$jobId}/status")
            ->assertOk()
            ->assertJsonPath('data.status', XlsImportJobStore::STATUS_READY)
            ->assertJsonPath('data.result.total', 7);
    }

    // ───────────────────────────────────────────────────────── RBAC / assignment regression

    public function test_bd_auto_assignment_still_applies_on_the_job_commit_path(): void
    {
        $bd = $this->bd();
        $otherBd = $this->bd();
        $store = app(XlsImportJobStore::class);
        $jobId = $store->create($bd->id, 'leads.xls');

        $converted = $store->storagePathFor($jobId);
        (new XlsxWriter($this->spreadsheet($this->sampleRows())))->save($converted);
        $store->markReady($jobId, $converted, ['total' => 2]);

        // A BD submitting someone else's id is still force-assigned to themselves.
        $this->import($bd, ['job_id' => $jobId, 'dry_run' => 'false', 'assigned_to' => $otherBd->id])
            ->assertOk();

        $this->assertSame($bd->id, Lead::where('business_name', 'Acme Dental')->sole()->assigned_to);
    }

    public function test_admin_must_still_pick_a_bd_when_committing_an_xls(): void
    {
        $admin = $this->admin();

        $this->import($admin, ['file' => $this->xlsFile($this->sampleRows()), 'dry_run' => 'false'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('assigned_to');
    }

    // ───────────────────────────────────────────────────────── format regressions

    public function test_xlsx_still_imports_unchanged(): void
    {
        $bd = $this->bd();

        $this->import($bd, ['file' => $this->xlsxFile($this->sampleRows()), 'dry_run' => 'false'])->assertOk();

        $this->assertDatabaseHas('crm_leads', ['business_name' => 'Acme Dental', 'assigned_to' => $bd->id]);
    }

    public function test_csv_still_imports_unchanged(): void
    {
        $bd = $this->bd();
        $csv = "Business Name,Owner's Name,Email\nCsv Co,Ana Reyes,ana@csv.ph\n";
        $path = tempnam(sys_get_temp_dir(), 'test-csv-').'.csv';
        file_put_contents($path, $csv);

        $this->import($bd, [
            'file' => new UploadedFile($path, 'leads.csv', null, null, true),
            'dry_run' => 'false',
        ])->assertOk();

        $this->assertDatabaseHas('crm_leads', ['business_name' => 'Csv Co', 'assigned_to' => $bd->id]);
    }

    public function test_txt_still_imports_unchanged(): void
    {
        $bd = $this->bd();
        $path = tempnam(sys_get_temp_dir(), 'test-txt-').'.txt';
        file_put_contents($path, "Business Name,Owner's Name\nTxt Co,Ben Cruz\n");

        $this->import($bd, [
            'file' => new UploadedFile($path, 'leads.txt', null, null, true),
            'dry_run' => 'false',
        ])->assertOk();

        $this->assertDatabaseHas('crm_leads', ['business_name' => 'Txt Co', 'assigned_to' => $bd->id]);
    }
}
