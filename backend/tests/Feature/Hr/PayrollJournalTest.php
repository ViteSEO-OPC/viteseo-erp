<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\GlAccount;
use App\Modules\Hr\Models\JournalEntry;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\JournalEntryService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * FIN-043 / MF-05 — payroll reaching the general ledger, and FIN-017 / MF-08 — the monthly filings.
 *
 * Payroll expense, employer statutory cost and every payable a run creates lived only in `hr_payslips`.
 * Nothing turned a run into double-entry, so payroll-to-accounting reconciliation was impossible by
 * construction: the two systems held the same money in incompatible shapes with no artifact between them.
 *
 * `test_the_entry_balances_to_the_centavo` IS THE ONE THAT MATTERS. The identity it rests on holds for
 * every payslip this system writes — `net_pay = gross − (sss + philhealth + pagibig + withholding_tax +
 * other_deductions)` — so `DR gross + DR employer shares` must equal `CR each payable + CR net pay
 * payable`. An unbalanced journal is a payroll bug, and posting one anyway would bury it inside
 * accounting, where nobody goes looking for payroll bugs.
 *
 * NOTHING IS SEEDED, and `test_it_refuses_until_the_chart_of_accounts_is_mapped` is the guard on that.
 * Account codes come from the company's own ledger; inventing "5100 — Salaries Expense" would produce a
 * journal that posts cleanly and reconciles to nothing.
 *
 * THE POSTED ENTRY IS FROZEN — deliberately unlike the loan ledger, the arrears and the leave-liability
 * report, which all derive on read. A posted journal is a statement about what the books said on a date;
 * if it re-derived, fixing a payslip six months later would rewrite last quarter's ledger.
 */
class PayrollJournalTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContributionSettingSeeder::class);

        $this->travelTo(Carbon::parse('2026-08-20 09:00', config('app.timezone_local')));

        WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 0, 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get();

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'employment_status' => 'regular',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
            'sss_number' => '34-1234567-8', 'philhealth_number' => '12-345678901-2',
            'pagibig_number' => '1234-5678-9012', 'tin' => '123-456-789-000',
        ]);

        $this->workAugust();
    }

    /** Present every August workday, so the run pays real money to post. */
    private function workAugust(): void
    {
        $tz = config('app.timezone_local');

        for ($day = Carbon::parse('2026-08-01', $tz); $day->month === 8; $day->addDay()) {
            if ($day->isWeekend()) {
                continue;
            }
            Attendance::create([
                'employee_id' => $this->employee->id,
                'work_date' => $day->toDateString(),
                'time_in' => $day->copy()->setTime(8, 0)->utc(),
                'time_out' => $day->copy()->setTime(17, 0)->utc(),
                'status' => 'present',
            ]);
        }
    }

    /** The company's chart of accounts. Codes are arbitrary here precisely because they always are. */
    private function mapChartOfAccounts(): void
    {
        $codes = [
            'salaries_expense' => ['5100', 'Salaries & Wages', 'expense'],
            'employer_contributions_expense' => ['5110', 'Employer Contributions', 'expense'],
            'sss_payable' => ['2100', 'SSS Payable', 'liability'],
            'philhealth_payable' => ['2110', 'PhilHealth Payable', 'liability'],
            'pagibig_payable' => ['2120', 'Pag-IBIG Payable', 'liability'],
            'withholding_tax_payable' => ['2130', 'Withholding Tax Payable', 'liability'],
            'other_deductions_payable' => ['2140', 'Other Deductions Payable', 'liability'],
            'net_pay_payable' => ['2150', 'Net Pay Payable', 'liability'],
        ];

        foreach ($codes as $role => [$code, $name, $type]) {
            GlAccount::create([
                'code' => $code, 'name' => $name, 'type' => $type, 'payroll_role' => $role,
            ]);
        }
    }

    /** Named `regularRun`, not `run` — PHPUnit's TestCase::run() is final. */
    private function regularRun(): PayrollRun
    {
        return app(PayrollService::class)->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());
    }

    private function service(): JournalEntryService
    {
        return app(JournalEntryService::class);
    }

    // --- the chart of accounts ------------------------------------------------------------------

    /**
     * THE GUARD ON NOT SEEDING. A half-mapped chart would post a journal that balances only because the
     * unmapped figures were silently dropped, which is worse than refusing.
     */
    public function test_it_refuses_until_the_chart_of_accounts_is_mapped(): void
    {
        $run = $this->regularRun();

        try {
            $this->service()->build($run);
            $this->fail('an unmapped chart of accounts should refuse');
        } catch (ValidationException $e) {
            $message = $e->validator->errors()->first('gl_accounts');
            // The message must NAME what is missing — "not configured" is not actionable.
            $this->assertStringContainsString('Salaries & wages expense', $message);
            $this->assertStringContainsString('Net pay payable', $message);
        }
    }

    public function test_a_partially_mapped_chart_still_refuses(): void
    {
        GlAccount::create([
            'code' => '5100', 'name' => 'Salaries', 'type' => 'expense',
            'payroll_role' => 'salaries_expense',
        ]);

        $this->expectException(ValidationException::class);
        $this->service()->build($this->regularRun());
    }

    /** Two accounts cannot claim the same role — the generator's choice would be arbitrary. */
    public function test_a_role_cannot_be_mapped_twice(): void
    {
        $this->mapChartOfAccounts();

        $this->actingAs($this->hrUser())
            ->postJson('/api/v1/hr/gl-accounts', [
                'code' => '5199', 'name' => 'Another salaries account', 'type' => 'expense',
                'payroll_role' => 'salaries_expense',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payroll_role');
    }

    public function test_the_chart_reports_which_roles_are_unmapped(): void
    {
        $unmapped = $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/gl-accounts')
            ->assertOk()
            ->json('meta.unmapped_roles');

        $this->assertCount(count(GlAccount::ROLES), $unmapped, 'nothing is mapped yet');
    }

    // --- the entry ------------------------------------------------------------------------------

    /**
     * THE INVARIANT. If the debits do not equal the credits, the payslips do not add up and the run
     * must not reach the books.
     */
    public function test_the_entry_balances_to_the_centavo(): void
    {
        $this->mapChartOfAccounts();

        $entry = $this->service()->build($this->regularRun());

        $this->assertTrue($entry->isBalanced());
        $this->assertGreaterThan(0, $entry->totalDebit(), 'and it is not trivially balanced at zero');
        $this->assertSame($entry->totalDebit(), $entry->totalCredit());
    }

    /** Salary expense is the run's gross — what the company incurred, after absences. */
    public function test_salary_expense_is_the_runs_gross(): void
    {
        $this->mapChartOfAccounts();
        $run = $this->regularRun();

        $entry = $this->service()->build($run);
        $line = $entry->lines->first(fn ($l) => $l->account->payroll_role === 'salaries_expense');

        $this->assertSame(round((float) $run->total_gross, 2), (float) $line->debit);
    }

    /** Both halves of each contribution sit on one payable — they are remitted on one filing. */
    public function test_each_agency_payable_carries_both_halves(): void
    {
        $this->mapChartOfAccounts();
        $run = $this->regularRun();

        $entry = $this->service()->build($run);
        $sss = $entry->lines->first(fn ($l) => $l->account->payroll_role === 'sss_payable');

        $expected = round(
            (float) $run->payslips()->sum('sss') + (float) $run->payslips()->sum('employer_sss'),
            2,
        );

        $this->assertSame($expected, (float) $sss->credit);
    }

    public function test_the_posting_date_defaults_to_the_cycle_end(): void
    {
        $this->mapChartOfAccounts();

        $entry = $this->service()->build($this->regularRun());

        $this->assertSame(
            '2026-08-15',
            $entry->posting_date->toDateString(),
            'the period the cost belongs to, not the day it was generated',
        );
    }

    public function test_the_posting_date_can_be_set(): void
    {
        $this->mapChartOfAccounts();

        $entry = $this->service()->build($this->regularRun(), '2026-08-31');

        $this->assertSame('2026-08-31', $entry->posting_date->toDateString());
    }

    /** Zero lines are omitted — they carry no information and clutter every export. */
    public function test_zero_figures_produce_no_line(): void
    {
        $this->mapChartOfAccounts();

        $entry = $this->service()->build($this->regularRun());
        $roles = $entry->lines->pluck('account.payroll_role')->all();

        // Nobody has a loan or an arrear in this fixture.
        $this->assertNotContains('other_deductions_payable', $roles);
    }

    /**
     * A refund flips sides rather than posting as a negative credit — withholding tax genuinely goes
     * negative on a year-end true-up or a leaver's final pay, and "CR −₱4,000" is not a journal line.
     */
    public function test_a_negative_figure_flips_sides_instead_of_going_negative(): void
    {
        $this->mapChartOfAccounts();
        $run = $this->regularRun();

        // Force the refund case the December true-up and final pay both produce.
        $slip = $run->payslips()->firstOrFail();
        $slip->update([
            'withholding_tax' => -1500,
            'net_pay' => round((float) $slip->net_pay + (float) $slip->withholding_tax + 1500, 2),
        ]);

        $entry = $this->service()->build($run->fresh());
        $tax = $entry->lines->first(fn ($l) => $l->account->payroll_role === 'withholding_tax_payable');

        $this->assertSame(1500.0, (float) $tax->debit, 'the refund is a debit');
        $this->assertSame(0.0, (float) $tax->credit);
        $this->assertTrue($entry->isBalanced(), 'and it still balances');
    }

    // --- posting, the duplicate guard, and reversal ----------------------------------------------

    public function test_posting_puts_the_entry_on_the_books(): void
    {
        $this->mapChartOfAccounts();
        $entry = $this->service()->build($this->regularRun());

        $posted = $this->service()->post($entry, $this->hrUser());

        $this->assertSame('posted', $posted->status);
        $this->assertNotNull($posted->posted_at);
        $this->assertNotNull($posted->posted_by);
    }

    /** THE DUPLICATE-POSTING GUARD. Posting the same run twice would double a payroll in the ledger. */
    public function test_a_run_cannot_be_posted_twice(): void
    {
        $this->mapChartOfAccounts();
        $run = $this->regularRun();
        $this->service()->post($this->service()->build($run), $this->hrUser());

        try {
            $this->service()->build($run->fresh());
            $this->fail('a posted run should refuse to be rebuilt');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already posted', $e->validator->errors()->first('payroll_run_id'));
        }
    }

    public function test_an_already_posted_entry_cannot_be_posted_again(): void
    {
        $this->mapChartOfAccounts();
        $entry = $this->service()->post($this->service()->build($this->regularRun()), $this->hrUser());

        $this->expectException(ValidationException::class);
        $this->service()->post($entry, $this->hrUser());
    }

    /** A draft, by contrast, is free to rebuild — it is not on the books yet. */
    public function test_a_draft_can_be_rebuilt(): void
    {
        $this->mapChartOfAccounts();
        $run = $this->regularRun();

        $first = $this->service()->build($run);
        $second = $this->service()->build($run->fresh());

        $this->assertSame($first->id, $second->id, 'the same entry, recomputed in place');
        $this->assertSame(1, JournalEntry::count());
    }

    /** Reversal writes a MIRROR — deleting would destroy the record that it was ever posted. */
    public function test_reversing_writes_a_mirror_and_keeps_the_original(): void
    {
        $this->mapChartOfAccounts();
        $original = $this->service()->post($this->service()->build($this->regularRun()), $this->hrUser());

        $mirror = $this->service()->reverse($original, $this->hrUser(), 'Wrong period');

        $this->assertSame('reversed', $original->fresh()->status);
        $this->assertSame($original->id, $mirror->reversal_of_id);
        $this->assertSame('posted', $mirror->status);

        // Every debit became a credit and vice versa, so the two together net to nothing.
        $this->assertSame($original->totalDebit(), $mirror->totalCredit());
        $this->assertSame($original->totalCredit(), $mirror->totalDebit());
    }

    /** Reversals post TODAY — back-dating one into a closed period is what they exist to avoid. */
    public function test_a_reversal_posts_today_not_on_the_originals_date(): void
    {
        $this->mapChartOfAccounts();
        $original = $this->service()->post(
            $this->service()->build($this->regularRun(), '2026-06-30'),
            $this->hrUser(),
        );

        $mirror = $this->service()->reverse($original, $this->hrUser(), 'Wrong period');

        $this->assertSame('2026-08-20', $mirror->posting_date->toDateString());
    }

    /** Once reversed, the run is free to post again. */
    public function test_a_reversed_run_can_be_posted_again(): void
    {
        $this->mapChartOfAccounts();
        $run = $this->regularRun();
        $original = $this->service()->post($this->service()->build($run), $this->hrUser());
        $this->service()->reverse($original, $this->hrUser(), 'Wrong period');

        $rebuilt = $this->service()->build($run->fresh());

        $this->assertSame('draft', $rebuilt->status);
        $this->assertNotSame($original->id, $rebuilt->id);
    }

    public function test_only_a_posted_entry_can_be_reversed(): void
    {
        $this->mapChartOfAccounts();
        $draft = $this->service()->build($this->regularRun());

        $this->expectException(ValidationException::class);
        $this->service()->reverse($draft, $this->hrUser(), 'Nope');
    }

    // --- the exports -----------------------------------------------------------------------------

    /** Drafts are not on the books, so they are not in the file the ledger is loaded from. */
    public function test_the_journal_export_excludes_drafts(): void
    {
        $this->mapChartOfAccounts();
        $this->service()->build($this->regularRun());

        $rows = $this->service()->exportRows('2026-01-01', '2026-12-31');

        $this->assertCount(1, $rows, 'the header row only');
    }

    public function test_the_journal_export_carries_every_posted_line(): void
    {
        $this->mapChartOfAccounts();
        $entry = $this->service()->post($this->service()->build($this->regularRun()), $this->hrUser());

        $rows = $this->service()->exportRows('2026-01-01', '2026-12-31');

        $this->assertCount($entry->lines->count() + 1, $rows);
        $this->assertSame('Posting date', $rows[0][0]);
    }

    /** Full precision — this is what the ledger is loaded from, so rounded figures have no place in it. */
    public function test_the_journal_export_keeps_centavos(): void
    {
        $this->mapChartOfAccounts();
        $entry = $this->service()->post($this->service()->build($this->regularRun()), $this->hrUser());

        $rows = $this->service()->exportRows('2026-01-01', '2026-12-31');
        $amounts = array_merge(array_column(array_slice($rows, 1), 5), array_column(array_slice($rows, 1), 6));

        foreach ($amounts as $amount) {
            $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', (string) $amount);
        }
        $this->assertTrue($entry->isBalanced());
    }

    public function test_hr_can_export_the_journal_over_http(): void
    {
        $this->mapChartOfAccounts();
        $this->service()->post($this->service()->build($this->regularRun()), $this->hrUser());

        $this->actingAs($this->hrUser())
            ->get('/api/v1/hr/accounting/export?from=2026-01-01&to=2026-12-31')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    public function test_an_employee_cannot_export_the_journal(): void
    {
        $this->actingAs($this->employee->user)
            ->getJson('/api/v1/hr/accounting/export?from=2026-01-01&to=2026-12-31')
            ->assertForbidden();
    }

    // --- the monthly remittance (FIN-017 / MF-08) ------------------------------------------------

    /**
     * THE FILING SHAPE THAT WAS MISSING. Under a semi-monthly schedule a month is two runs, so the one
     * figure that goes on the form meant adding two per-run CSVs by hand.
     */
    public function test_the_remittance_aggregates_both_runs_in_the_month(): void
    {
        $payroll = app(PayrollService::class);
        $first = $payroll->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());
        $second = $payroll->run('2026-08-16', '2026-08-31', 'semi_monthly', $this->hrUser());

        foreach ([$first, $second] as $run) {
            foreach ($run->payslips as $slip) {
                $payroll->markDone($slip, $this->hrUser());
            }
        }

        $rows = $this->service()->remittanceRows('2026-08', 'sss');

        $this->assertSame(2, (int) $rows[1][3], 'both runs counted');

        $expected = round(
            (float) $first->payslips()->sum('sss') + (float) $second->payslips()->sum('sss'),
            2,
        );
        $this->assertSame(number_format($expected, 2, '.', ''), $rows[1][4]);
    }

    /**
     * A remittance is a statement about money that moved. A computed-but-unpaid run has created no
     * obligation to remit yet.
     */
    public function test_the_remittance_counts_only_disbursed_payslips(): void
    {
        $this->regularRun(); // computed, never disbursed

        $rows = $this->service()->remittanceRows('2026-08', 'sss');

        $this->assertCount(2, $rows, 'the header and the TOTAL row, with no employees between them');
        $this->assertSame('TOTAL', $rows[1][0]);
    }

    /** Σ export rows == Σ payslip columns for the period, to the centavo — the finding's own validation. */
    public function test_the_remittance_total_matches_the_payslips_exactly(): void
    {
        $payroll = app(PayrollService::class);
        $run = $payroll->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());
        foreach ($run->payslips as $slip) {
            $payroll->markDone($slip, $this->hrUser());
        }

        foreach (['sss' => 'employer_sss', 'philhealth' => 'employer_philhealth', 'pagibig' => 'employer_pagibig'] as $agency => $employerColumn) {
            $rows = $this->service()->remittanceRows('2026-08', $agency);
            $total = $rows[count($rows) - 1];

            $expected = round(
                (float) $run->payslips()->sum($agency === 'sss' ? 'sss' : $agency)
                    + (float) $run->payslips()->sum($employerColumn),
                2,
            );

            $this->assertSame(
                number_format($expected, 2, '.', ''),
                $total[6],
                "the {$agency} remittance total must equal the payslips to the centavo",
            );
        }
    }

    /** Withholding tax has no employer counterpart — the company remits what it withheld. */
    public function test_the_tax_remittance_has_no_employer_share(): void
    {
        $payroll = app(PayrollService::class);
        $run = $payroll->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());
        foreach ($run->payslips as $slip) {
            $payroll->markDone($slip, $this->hrUser());
        }

        $rows = $this->service()->remittanceRows('2026-08', 'tax');

        $this->assertSame('0.00', $rows[1][5]);
    }

    /** The file IS the filing, so it carries the full government number, not the masked display form. */
    public function test_the_remittance_carries_the_full_government_number(): void
    {
        $payroll = app(PayrollService::class);
        $run = $payroll->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());
        foreach ($run->payslips as $slip) {
            $payroll->markDone($slip, $this->hrUser());
        }

        $rows = $this->service()->remittanceRows('2026-08', 'sss');

        $this->assertSame('34-1234567-8', $rows[1][2]);
        $this->assertStringNotContainsString('•', $rows[1][2]);
    }

    public function test_an_unknown_agency_is_refused(): void
    {
        $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/accounting/remittance?month=2026-08&agency=gsis')
            ->assertStatus(422)
            ->assertJsonValidationErrors('agency');
    }

    public function test_hr_can_export_a_remittance_over_http(): void
    {
        $this->actingAs($this->hrUser())
            ->get('/api/v1/hr/accounting/remittance?month=2026-08&agency=philhealth')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    public function test_an_employee_cannot_export_a_remittance(): void
    {
        $this->actingAs($this->employee->user)
            ->getJson('/api/v1/hr/accounting/remittance?month=2026-08&agency=sss')
            ->assertForbidden();
    }
}
