<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Services\Import\XlsImportJobStore;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Retention for abandoned `.xls` imports.
 *
 * Commit and failure delete their files eagerly. This covers the third ending — a conversion that is
 * previewed and never confirmed: its cache record expires on its own, but a cache expiry cannot
 * unlink a file, so without this prune the generated `.xlsx` would remain on disk indefinitely.
 *
 * The safety rule under test is that "expired" means **no live job record**, not "old". A BD may sit
 * on a preview for an hour; their files must survive that, and must disappear once the record does.
 */
class PruneXlsImportsTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->dir = storage_path('app/xls-imports');
        if (! is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $leftover) {
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

    /** Create the two files a queued conversion parks, optionally aged past the grace period. */
    private function filesFor(string $jobId, bool $aged = true): array
    {
        $store = app(XlsImportJobStore::class);
        $converted = $store->storagePathFor($jobId);
        $source = $converted.'.source.xls';

        file_put_contents($converted, 'converted');
        file_put_contents($source, 'source');

        if ($aged) {
            $old = time() - 7200;
            touch($converted, $old);
            touch($source, $old);
        }

        return [$converted, $source];
    }

    public function test_it_removes_files_whose_job_record_has_expired(): void
    {
        $store = app(XlsImportJobStore::class);
        $jobId = $store->create($this->bd()->id, 'abandoned.xls');
        [$converted, $source] = $this->filesFor($jobId);

        // Simulate the record's TTL lapsing while the files remain — the abandoned-import case.
        $store->forgetRecordOnly($jobId);

        $this->artisan('crm:prune-xls-imports')->assertSuccessful();

        $this->assertFileDoesNotExist($converted);
        $this->assertFileDoesNotExist($source);
    }

    public function test_it_never_touches_a_live_job_however_old_its_files_are(): void
    {
        $store = app(XlsImportJobStore::class);
        $jobId = $store->create($this->bd()->id, 'live.xls');
        // Deliberately aged well past the grace period: age alone must never justify deletion while
        // the record is alive — a BD may still be reviewing this preview.
        [$converted, $source] = $this->filesFor($jobId, aged: true);

        $this->artisan('crm:prune-xls-imports')->assertSuccessful();

        $this->assertFileExists($converted);
        $this->assertFileExists($source);
    }

    public function test_it_leaves_recent_unreferenced_files_alone(): void
    {
        $store = app(XlsImportJobStore::class);
        $jobId = $store->create($this->bd()->id, 'inflight.xls');
        // Fresh files with no record model the startup race: the controller creates the record
        // before moving the upload, so a file can briefly exist "unreferenced" mid-request.
        [$converted, $source] = $this->filesFor($jobId, aged: false);
        $store->forgetRecordOnly($jobId);

        $this->artisan('crm:prune-xls-imports')->assertSuccessful();

        $this->assertFileExists($converted);
        $this->assertFileExists($source);
    }

    public function test_it_is_idempotent(): void
    {
        $store = app(XlsImportJobStore::class);
        $jobId = $store->create($this->bd()->id, 'abandoned.xls');
        [$converted] = $this->filesFor($jobId);
        $store->forgetRecordOnly($jobId);

        $this->artisan('crm:prune-xls-imports')->assertSuccessful();
        $this->assertFileDoesNotExist($converted);

        // A second run has nothing to do and must not error.
        $this->artisan('crm:prune-xls-imports')->assertSuccessful();
        $this->assertSame(0, app(XlsImportJobStore::class)->pruneOrphans(0));
    }

    public function test_it_is_a_no_op_when_nothing_has_ever_been_imported(): void
    {
        $this->assertSame(0, app(XlsImportJobStore::class)->pruneOrphans(0));
        $this->artisan('crm:prune-xls-imports')->assertSuccessful();
    }

    public function test_the_grace_period_is_configurable(): void
    {
        $store = app(XlsImportJobStore::class);
        $jobId = $store->create($this->bd()->id, 'abandoned.xls');
        [$converted] = $this->filesFor($jobId, aged: false);
        $store->forgetRecordOnly($jobId);

        // Default grace protects it; zero grace collects it.
        $this->artisan('crm:prune-xls-imports')->assertSuccessful();
        $this->assertFileExists($converted);

        $this->artisan('crm:prune-xls-imports', ['--grace' => 0])->assertSuccessful();
        $this->assertFileDoesNotExist($converted);
    }

    public function test_a_committed_import_leaves_nothing_for_the_prune_to_find(): void
    {
        $store = app(XlsImportJobStore::class);
        $jobId = $store->create($this->bd()->id, 'committed.xls');
        [$converted, $source] = $this->filesFor($jobId);
        $store->attachSource($jobId, $source);
        $store->markReady($jobId, $converted, ['summary' => ['total' => 1]]);

        // forget() is what commit calls — the eager path. The prune is only a backstop.
        $store->forget($jobId);

        $this->assertFileDoesNotExist($converted);
        $this->assertFileDoesNotExist($source);
        $this->assertSame(0, $store->pruneOrphans(0));
    }
}
