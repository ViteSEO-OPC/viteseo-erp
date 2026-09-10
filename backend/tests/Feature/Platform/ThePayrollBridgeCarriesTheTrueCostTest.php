<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\User;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Services\OrgOnboardingService;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Payroll -> Ledger is the one cross-module bridge that survived Accounting's
 * move into this repo (Inventory -> Ledger and Booking -> Ledger were deleted
 * with the modules they read from). It is also the only place where money
 * crosses from one system into another's books, which makes a wrong figure
 * here a wrong figure in a client's financial statements.
 *
 * What it is FOR is the employer share. Payroll tells the client it paid
 * 100,000; the ledger has to say 105,200, because SSS, PhilHealth and Pag-IBIG
 * cost the employer on top of the payslip. A bridge that posted only the gross
 * would look right on every screen and understate the cost of staff all year.
 *
 * Two employees, round numbers, so the entry can be read by hand:
 *
 *   Dr  Salaries Expense              100,000
 *   Dr  SSS / PhilHealth / Pag-IBIG     5,200   (employer share)
 *       Cr Net Pay Payable                        86,800
 *       Cr SSS / PhilHealth / Pag-IBIG Payable      8,400   (employee + employer)
 *       Cr Withholding Tax Payable                 10,000
 */
class ThePayrollBridgeCarriesTheTrueCostTest extends TestCase
{
    use RefreshDatabase;

    private int $orgId;

    private int $runId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        // Both halves: the bridge is gated on hr AND accounting.payroll_posting.
        $result = app(OrgOnboardingService::class)->createOrg(
            'Bituin Digital Corp', 'Maria', 'maria@bituin.ph', 'password-123',
            ['accounting' => 'full', 'hr' => 'full'],
        );
        $this->orgId = $result['org']->id;
        Sanctum::actingAs($result['admin']);

        $this->runId = DB::table('hr_payroll_runs')->insertGetId([
            'org_id' => $this->orgId,
            'cycle_start' => '2026-09-01',
            'cycle_end' => '2026-09-15',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (['a', 'b'] as $tag) {
            DB::table('hr_payslips')->insert([
                'org_id' => $this->orgId,
                'payroll_run_id' => $this->runId,
                'employee_id' => $this->employeeIn($this->orgId, $tag),
                'base_salary' => 50000, 'gross' => 50000,
                'sss' => 1000, 'philhealth' => 500, 'pagibig' => 100,
                'employer_sss' => 2000, 'employer_philhealth' => 500, 'employer_pagibig' => 100,
                'withholding_tax' => 5000, 'net_pay' => 43400,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_payroll_from_outside_this_platform_can_be_imported_as_a_csv(): void
    {
        // The path for a client whose HR lives elsewhere: same ledger entry, no
        // hr_payroll_runs row behind it. Header names are matched loosely on
        // purpose -- every payroll export spells these columns differently.
        //
        // The fund is spelled "Pag-IBIG", with the hyphen, because that is its
        // name. The header normaliser turned that into `pag_ibig_employee` and
        // matched none of the keys it was looking for, so Pag-IBIG imported as
        // zero -- and the entry then failed to balance, telling the client
        // "Debits (105,000.00) must equal credits (104,800.00)": an error about
        // double-entry bookkeeping when the real problem was a column name.
        $csv = <<<'CSV'
        Employee,Gross Pay,SSS Employee,PhilHealth Employee,Pag-IBIG Employee,SSS Employer,PhilHealth Employer,Pag-IBIG Employer,Withholding Tax,Net Pay
        Ana Reyes,50000,1000,500,100,2000,500,100,5000,43400
        Leo Cruz,50000,1000,500,100,2000,500,100,5000,43400
        CSV;

        $entry = $this->postJson('/api/v1/accounting/payroll-import', [
            'file' => UploadedFile::fake()->createWithContent('payroll-september.csv', $csv),
            'entry_date' => '2026-09-15',
            'memo' => 'September payroll (imported)',
        ])->assertCreated();

        $lines = collect($entry->json('data.lines'))->keyBy('account_code');

        $this->assertSame(100000.0, (float) $lines['5000']['debit'], 'Salaries Expense');
        $this->assertSame(4000.0, (float) $lines['5100']['debit'], 'the employer share survives the import');
        $this->assertSame(86800.0, (float) $lines['2200']['credit'], 'Net Pay Payable');
        $this->assertSame(10000.0, (float) $lines['2130']['credit'], 'Withholding Tax Payable');

        $debits = collect($entry->json('data.lines'))->sum(fn ($l) => (float) $l['debit']);
        $this->assertSame(105200.0, $debits, 'the same true cost as the in-platform path');
    }

    public function test_an_empty_csv_is_refused_rather_than_posting_an_empty_entry(): void
    {
        $this->postJson('/api/v1/accounting/payroll-import', [
            'file' => UploadedFile::fake()->createWithContent('empty.csv', "Employee,Gross Pay\n"),
            'entry_date' => '2026-09-15',
        ])->assertStatus(422);
    }

    /** A real employee row -- `hr_payslips.employee_id` is a foreign key. */
    private function employeeIn(int $orgId, string $tag): int
    {
        return CurrentOrg::runAs($orgId, function () use ($tag, $orgId): int {
            $user = User::create([
                'name' => "Person {$tag}",
                'email' => "{$tag}-{$orgId}@client.test",
                'password' => 'password-123',
            ]);

            $department = Department::firstOrCreate(
                ['code' => "D-{$orgId}"],
                ['name' => 'Operations', 'is_active' => true],
            );

            return Employee::create([
                'user_id' => $user->id,
                'department_id' => $department->id,
                'employee_no' => "E-{$orgId}-{$tag}",
                'job_title' => 'Staff',
                'hire_date' => '2026-01-15',
            ])->id;
        });
    }

    public function test_the_run_is_listed_with_the_employer_share_on_top_of_the_gross(): void
    {
        $runs = $this->getJson('/api/v1/accounting/payroll-runs')->assertOk()->json('data');

        $this->assertCount(1, $runs);
        $this->assertSame(2, (int) $runs[0]['employees']);
        $this->assertSame(100000.0, (float) $runs[0]['gross']);
        $this->assertSame(5200.0, (float) $runs[0]['employer_contributions'], 'SSS + PhilHealth + Pag-IBIG, employer side');
        $this->assertSame(105200.0, (float) $runs[0]['total_cost'], 'what staff actually cost');
        $this->assertFalse($runs[0]['posted']);
    }

    public function test_posting_writes_a_balanced_entry_that_names_every_statutory_account(): void
    {
        $entry = $this->postJson("/api/v1/accounting/payroll-runs/{$this->runId}/post-to-ledger")
            ->assertCreated();

        $lines = collect($entry->json('data.lines'))->keyBy('account_code');

        $this->assertSame(100000.0, (float) $lines['5000']['debit'], 'Salaries Expense');
        $this->assertSame(4000.0, (float) $lines['5100']['debit'], 'SSS Employer Expense');
        $this->assertSame(1000.0, (float) $lines['5110']['debit'], 'PhilHealth Employer Expense');
        $this->assertSame(200.0, (float) $lines['5120']['debit'], 'Pag-IBIG Employer Expense');

        $this->assertSame(86800.0, (float) $lines['2200']['credit'], 'Net Pay Payable');
        $this->assertSame(6000.0, (float) $lines['2100']['credit'], 'SSS Payable: employee 2,000 + employer 4,000');
        $this->assertSame(2000.0, (float) $lines['2110']['credit'], 'PhilHealth Payable');
        $this->assertSame(400.0, (float) $lines['2120']['credit'], 'Pag-IBIG Payable');
        $this->assertSame(10000.0, (float) $lines['2130']['credit'], 'Withholding Tax Payable');

        $debits = collect($entry->json('data.lines'))->sum(fn ($l) => (float) $l['debit']);
        $credits = collect($entry->json('data.lines'))->sum(fn ($l) => (float) $l['credit']);
        $this->assertSame(105200.0, $debits);
        $this->assertSame($debits, $credits, 'a payroll entry balances or it is not an entry');
    }

    public function test_a_run_cannot_be_posted_to_the_ledger_twice(): void
    {
        $this->postJson("/api/v1/accounting/payroll-runs/{$this->runId}/post-to-ledger")->assertCreated();

        // Double-posting would double the client's payroll expense for the year.
        $this->postJson("/api/v1/accounting/payroll-runs/{$this->runId}/post-to-ledger")
            ->assertStatus(422)
            ->assertJsonPath('errors.run.0', 'This payroll run has already been posted to the ledger.');

        $this->assertTrue($this->getJson('/api/v1/accounting/payroll-runs')->json('data.0.posted'));
    }

    public function test_another_orgs_payroll_run_cannot_be_posted_into_these_books(): void
    {
        $other = app(OrgOnboardingService::class)->createOrg(
            'Sinag Marketing', 'Jose', 'jose@sinag.ph', 'password-123',
            ['accounting' => 'full', 'hr' => 'full'],
        );

        $foreignRun = DB::table('hr_payroll_runs')->insertGetId([
            'org_id' => $other['org']->id,
            'cycle_start' => '2026-09-01', 'cycle_end' => '2026-09-15',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('hr_payslips')->insert([
            'org_id' => $other['org']->id, 'payroll_run_id' => $foreignRun,
            'employee_id' => $this->employeeIn($other['org']->id, 'z'),
            'base_salary' => 90000, 'gross' => 90000, 'net_pay' => 90000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Still signed in as Bituin's admin.
        $this->postJson("/api/v1/accounting/payroll-runs/{$foreignRun}/post-to-ledger")
            ->assertStatus(422)
            ->assertJsonPath('errors.run.0', 'Payroll run not found.');

        $this->assertSame(
            0,
            CurrentOrg::runAs($this->orgId, fn () => DB::table('acc_journal_entries')->where('org_id', $this->orgId)->count()),
            'nothing was written into the wrong books',
        );
    }
}
