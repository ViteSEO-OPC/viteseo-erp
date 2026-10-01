<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use App\Modules\Crm\Services\Import\LeadImportInProgressException;
use App\Modules\Crm\Services\Import\XlsImportJobStore;
use App\Modules\Crm\Services\LeadImportService;
use App\Services\OrgOnboardingService;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use RuntimeException;
use Tests\TestCase;

/**
 * Two lead-import commits in the same organization must not both create the same leads (CEO audit
 * crm-06, the concurrent-duplicate part).
 *
 * The commit reads the dedup index, classifies every row in PHP and only then writes. With nothing
 * serializing those steps, a second commit that reads the index before the first has written sees
 * none of the first's rows and inserts them again. A per-organization commit lock now covers dedup
 * read → classification → transaction; a commit that cannot take it is refused with 409.
 *
 * The races are reproduced deterministically in one process: the second import runs from inside the
 * first import's `creating`/`restoring` model event, i.e. after the first has read the dedup index
 * and before it has written anything.
 */
class LeadImportConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const EMAILS = ['alpha@race.test', 'beta@race.test', 'gamma@race.test'];

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

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);

        return $user;
    }

    /** @return list<list<string>> */
    private function rows(): array
    {
        return [
            ['Business Name', 'Email', 'Contact Number'],
            ['Race Alpha Co', self::EMAILS[0], '0917 000 0001'],
            ['Race Beta Co', self::EMAILS[1], '0917 000 0002'],
            ['Race Gamma Co', self::EMAILS[2], '0917 000 0003'],
        ];
    }

    /** @param list<list<string>> $rows */
    private function csv(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'test-race-').'.csv';
        $handle = fopen($path, 'w');
        foreach ($rows as $cells) {
            fputcsv($handle, $cells);
        }
        fclose($handle);

        return new UploadedFile($path, 'leads.csv', null, null, true);
    }

    /** @param list<list<string>> $rows */
    private function xlsx(array $rows): string
    {
        $book = new Spreadsheet;
        foreach ($rows as $r => $cells) {
            foreach ($cells as $c => $value) {
                $book->getActiveSheet()->setCellValue([$c + 1, $r + 1], $value);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'test-race-').'.xlsx';
        (new XlsxWriter($book))->save($path);

        return $path;
    }

    /** Multipart (the upload needs it), answered as JSON. @param array<string, mixed> $params */
    private function send(User $as, array $params): TestResponse
    {
        Sanctum::actingAs($as);

        return $this->post('/api/v1/crm/leads/import', $params, ['Accept' => 'application/json']);
    }

    private function commit(User $as, ?UploadedFile $file = null, array $extra = []): TestResponse
    {
        return $this->send($as, ['file' => $file ?? $this->csv($this->rows()), 'dry_run' => 'false', ...$extra]);
    }

    /** The org commit lock, taken by "another import". */
    private function holdCommitLock(User $member): Lock
    {
        $lock = Cache::lock(LeadImportService::COMMIT_LOCK_PREFIX.$member->currentOrgId(), 600);
        $this->assertTrue($lock->get(), 'the test must be able to take the lock first');

        return $lock;
    }

    private function raceLeads(): int
    {
        return Lead::withTrashed()->whereIn('email', self::EMAILS)->count();
    }

    /**
     * Run $second once, from inside the FIRST import's `creating` event — after the first has read the dedup
     * index and classified its rows, before it has written anything. Returns a reference to the outcome.
     */
    private function interleaveOnCreating(callable $second): \stdClass
    {
        $outcome = new \stdClass;
        $outcome->value = null;
        $fired = false;

        Lead::creating(function () use (&$fired, $second, $outcome): void {
            if ($fired) {
                return;
            }
            $fired = true;
            try {
                $outcome->value = $second();
            } catch (LeadImportInProgressException) {
                $outcome->value = 'refused';
            }
        });

        return $outcome;
    }

    // ── A. The audit race, reproduced ───────────────────────────────────────────────────────────

    public function test_a_second_commit_interleaved_before_the_first_writes_creates_no_duplicates(): void
    {
        $bd = $this->userWithRole('crm_bd');

        $second = $this->interleaveOnCreating(
            fn () => app(LeadImportService::class)->import($this->csv($this->rows()), $bd->id, false, $bd->id),
        );

        $this->commit($bd)->assertOk()->assertJsonPath('data.importedCount', 3);

        $this->assertSame(3, $this->raceLeads(), 'exactly one set of leads');
        $this->assertSame('refused', $second->value, 'the interleaved commit must not run');
        foreach (self::EMAILS as $email) {
            $this->assertSame(1, Lead::withTrashed()->where('email', $email)->count(), $email);
        }
        $ids = Lead::whereIn('email', self::EMAILS)->pluck('id');
        $this->assertSame(3, Activity::query()->where('event', 'created')->where('subject_type', (new Lead)->getMorphClass())->whereIn('subject_id', $ids)->count());
        $followUpsPerLead = PlannerEvent::query()->whereIn('lead_id', $ids)->selectRaw('lead_id, COUNT(*) AS c')->groupBy('lead_id')->pluck('c');
        $this->assertTrue($followUpsPerLead->every(fn ($c): bool => (int) $c <= 1), 'no lead carries a second follow-up');
    }

    // ── B. Double restore ───────────────────────────────────────────────────────────────────────

    public function test_two_interleaved_commits_restore_an_archived_lead_only_once(): void
    {
        $bd = $this->userWithRole('crm_bd');
        $archived = Lead::factory()->assignedTo($bd)->create(['business_name' => 'Race Archived Co', 'email' => 'archived@race.test']);
        $archived->delete();
        $rows = [['Business Name', 'Email'], ['Race Archived Co', 'archived@race.test']];

        $outcome = new \stdClass;
        $outcome->value = null;
        $fired = false;
        Lead::restoring(function () use (&$fired, $outcome, $rows, $bd): void {
            if ($fired) {
                return;
            }
            $fired = true;
            try {
                $outcome->value = app(LeadImportService::class)->import($this->csv($rows), $bd->id, false, $bd->id);
            } catch (LeadImportInProgressException) {
                $outcome->value = 'refused';
            }
        });

        $this->commit($bd, $this->csv($rows))->assertOk()->assertJsonPath('data.summary.restored', 1);

        $this->assertSame(1, Activity::query()->where('event', 'lead.restored')->where('subject_id', $archived->id)->count(), 'one restore activity');
        $this->assertSame('refused', $outcome->value);
        $this->assertSame(1, Lead::withTrashed()->where('email', 'archived@race.test')->count(), 'no duplicate lead');
        $this->assertNull($archived->fresh()->deleted_at, 'restored');
    }

    // ── C. Different owners ─────────────────────────────────────────────────────────────────────

    public function test_a_bd_and_an_admin_importing_the_same_sheet_at_once_create_one_owner_set(): void
    {
        $bd = $this->userWithRole('crm_bd');
        $otherBd = $this->userWithRole('crm_bd');
        $admin = $this->userWithRole('crm_admin');

        $second = $this->interleaveOnCreating(
            fn () => app(LeadImportService::class)->import($this->csv($this->rows()), $admin->id, false, $otherBd->id),
        );

        $this->commit($bd)->assertOk();

        $this->assertSame(0, Lead::withTrashed()->where('assigned_to', $otherBd->id)->count(), 'no second owner set');
        $this->assertSame(3, $this->raceLeads());
        $this->assertSame('refused', $second->value);
        $this->assertSame(3, Lead::whereIn('email', self::EMAILS)->where('assigned_to', $bd->id)->count());
    }

    // ── D. Lock held ────────────────────────────────────────────────────────────────────────────

    public function test_a_commit_while_the_organization_import_lock_is_held_is_refused_with_409_and_writes_nothing(): void
    {
        $bd = $this->userWithRole('crm_bd');
        $lock = $this->holdCommitLock($bd);

        $this->commit($bd)
            ->assertStatus(409)
            ->assertExactJson(['message' => LeadImportInProgressException::MESSAGE]);

        $this->assertSame(0, $this->raceLeads());
        $this->assertSame(0, Activity::query()->where('event', 'created')->where('subject_type', (new Lead)->getMorphClass())->count());
        $this->assertTrue($lock->release(), 'the other import still owns its lock');
    }

    // ── E. Preview while locked ─────────────────────────────────────────────────────────────────

    public function test_previews_never_take_the_lock_and_still_work_while_it_is_held(): void
    {
        $bd = $this->userWithRole('crm_bd');
        $lock = $this->holdCommitLock($bd);
        $activities = Activity::query()->count();
        $events = PlannerEvent::withTrashed()->count();

        $this->send($bd, ['file' => $this->csv($this->rows()), 'dry_run' => 'true'])
            ->assertOk()
            ->assertJsonPath('data.dryRun', true)
            ->assertJsonPath('data.summary.valid', 3)
            ->assertJsonPath('data.importedCount', 0);

        $xlsx = new UploadedFile($this->xlsx($this->rows()), 'leads.xlsx', null, null, true);
        $this->send($bd, ['file' => $xlsx, 'dry_run' => 'true'])
            ->assertOk()
            ->assertJsonPath('data.summary.valid', 3);

        $this->assertSame(0, $this->raceLeads());
        $this->assertSame($activities, Activity::query()->count());
        $this->assertSame($events, PlannerEvent::withTrashed()->count());
        $this->assertTrue($lock->release());
    }

    // ── F. Organization isolation ───────────────────────────────────────────────────────────────

    public function test_one_organizations_import_lock_does_not_block_another_organization(): void
    {
        $bd = $this->userWithRole('crm_bd');
        $lock = $this->holdCommitLock($bd);

        $otherOrg = app(OrgOnboardingService::class)->createOrg(
            'Race Other Org', 'Race Other Owner', 'owner-race-other@client.ph', 'password-123', ['crm' => 'full'],
        )['org'];
        $otherBd = User::factory()->orgless()->create(['status' => 'active']);
        $otherBd->assignRole('crm_bd');
        $otherOrg->users()->attach($otherBd->id, ['is_default' => true]);
        $this->assertNotSame($bd->currentOrgId(), $otherBd->currentOrgId());

        CurrentOrg::clear();
        $this->commit($otherBd)->assertOk()->assertJsonPath('data.importedCount', 3);

        $this->assertSame(3, Lead::withoutGlobalScopes()->where('org_id', $otherOrg->id)->whereIn('email', self::EMAILS)->count());
        $this->assertTrue($lock->release());
    }

    // ── G. Lock release ─────────────────────────────────────────────────────────────────────────

    public function test_the_lock_is_released_after_a_successful_commit(): void
    {
        $bd = $this->userWithRole('crm_bd');

        $this->commit($bd)->assertOk();

        $lock = Cache::lock(LeadImportService::COMMIT_LOCK_PREFIX.$bd->currentOrgId(), 600);
        $this->assertTrue($lock->get(), 'free again after the commit');
        $lock->release();
    }

    public function test_the_lock_is_released_after_a_commit_that_fails_and_rolls_back(): void
    {
        $bd = $this->userWithRole('crm_bd');
        Lead::creating(function (): void {
            throw new RuntimeException('simulated failure mid-commit');
        });

        $this->commit($bd)->assertStatus(500);

        $this->assertSame(0, $this->raceLeads(), 'rolled back');
        $lock = Cache::lock(LeadImportService::COMMIT_LOCK_PREFIX.$bd->currentOrgId(), 600);
        $this->assertTrue($lock->get(), 'a failed commit must not leave the organization locked');
        $lock->release();
    }

    // ── H. Large .xls job commit ────────────────────────────────────────────────────────────────

    /**
     * The job commit goes through the same import(dryRun: false), so the same lock protects it. The job is
     * claimed (and its file removed) before the import runs, so a refused job commit cannot be replayed and
     * the user re-uploads — a documented edge case; XlsImportJobStore is out of scope (CTO crm-07).
     */
    public function test_a_large_xls_job_commit_is_protected_by_the_same_organization_lock(): void
    {
        $bd = $this->userWithRole('crm_bd');
        $store = app(XlsImportJobStore::class);
        $jobId = $store->create($bd->id, 'leads.xls');
        $converted = $store->storagePathFor($jobId);
        copy($this->xlsx($this->rows()), $converted);
        $store->markReady($jobId, $converted, ['total' => 3]);
        $lock = $this->holdCommitLock($bd);

        $this->send($bd, ['job_id' => $jobId, 'dry_run' => 'false'])
            ->assertStatus(409)
            ->assertExactJson(['message' => LeadImportInProgressException::MESSAGE]);

        $this->assertSame(0, $this->raceLeads());
        $lock->release();

        $replay = $this->send($bd, ['job_id' => $jobId, 'dry_run' => 'false']);
        $this->assertContains($replay->getStatusCode(), [404, 422], 'the claimed job cannot be replayed');
        $this->assertSame(0, $this->raceLeads());
    }

    // ── I. Sequential idempotency is unchanged ──────────────────────────────────────────────────

    public function test_importing_the_same_sheet_twice_in_sequence_still_reports_duplicates(): void
    {
        $bd = $this->userWithRole('crm_bd');

        $this->commit($bd)->assertOk()
            ->assertJsonPath('data.importedCount', 3)
            ->assertJsonPath('data.summary.duplicates', 0);

        $this->commit($bd)->assertOk()
            ->assertJsonPath('data.importedCount', 0)
            ->assertJsonPath('data.summary.valid', 0)
            ->assertJsonPath('data.summary.duplicates', 3);

        $this->assertSame(3, $this->raceLeads());
    }
}
