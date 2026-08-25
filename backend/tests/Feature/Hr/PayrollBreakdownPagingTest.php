<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The per-employee breakdown of a payroll run, served ONE PAGE at a time.
 *
 * The run detail endpoint used to load every payslip in the run — each with its employee, user
 * and department — so that a table could draw ten rows of it. For a run the size of the company
 * that is the entire payroll held in memory and serialised into one response.
 *
 * Paging it is the easy half. The dangerous half is everything the screen states ABOUT the run,
 * all of which was computed by adding up that loaded collection:
 *
 *   · the register totals — gross, deductions, net — printed at the top of the screen;
 *   · how many payslips a correction left owing, and by how much;
 *   · the mirror image, overpaid after the money had gone out;
 *   · and `can_lock`, which gates the button that seals a payroll cycle permanently.
 *
 * Every one of those would have quietly become a statement about ten employees. Three are money
 * and the fourth is irreversible, and none of them would look wrong. This file exists to hold
 * each of them to the whole run while the table beneath shows a page.
 */
class PayrollBreakdownPagingTest extends HrTestCase
{
    use RefreshDatabase;

    private PayrollRun $run;

    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->run = PayrollRun::create([
            'cycle_start' => '2026-08-01', 'cycle_end' => '2026-08-15',
            'frequency' => 'semi_monthly', 'type' => 'regular', 'status' => 'completed',
            'total_gross' => 0, 'total_deductions' => 0, 'total_net' => 0, 'employee_count' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function payslip(array $attrs = []): Payslip
    {
        static $n = 0;
        $n++;

        $employee = Employee::create([
            'user_id' => User::factory()->create(['name' => "Person {$n}"])->id,
            'department_id' => $this->dept->id,
            'employee_no' => sprintf('E-%03d', $n),
            'job_title' => 'Staff', 'hire_date' => '2020-01-01', 'employment_status' => 'regular',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);

        return Payslip::create(array_merge([
            'payroll_run_id' => $this->run->id,
            'employee_id' => $employee->id,
            'base_salary' => 30000,
            'gross' => 1000,
            'absence_deduction' => 0,
            'net_pay' => 900,
            'status' => 'disbursed',
            'disbursed_amount' => 900,
        ], $attrs));
    }

    private function slips(int $n, array $attrs = []): void
    {
        foreach (range(1, $n) as $i) {
            $this->payslip($attrs);
        }
    }

    private function detail(string $query = ''): array
    {
        return $this->actingAs($this->hrUser())
            ->getJson("/api/v1/hr/payroll/runs/{$this->run->id}{$query}")
            ->assertOk()
            ->json('data');
    }

    // --- the breakdown itself -------------------------------------------------------------

    public function test_the_breakdown_is_one_page_and_states_what_it_is_a_page_of(): void
    {
        $this->slips(45);

        $detail = $this->detail('?per_page=10');

        $this->assertCount(10, $detail['breakdown']);
        $this->assertSame(45, $detail['breakdown_meta']['total']);
        $this->assertSame(1, $detail['breakdown_meta']['page']);
    }

    public function test_walking_every_page_returns_each_payslip_exactly_once(): void
    {
        $this->slips(37);

        $seen = [];
        foreach (range(1, 4) as $page) {
            foreach ($this->detail("?per_page=10&page={$page}")['breakdown'] as $row) {
                $seen[] = $row['id'];
            }
        }

        $this->assertCount(37, $seen, 'a payslip was dropped or duplicated across the pages');
        $this->assertCount(37, array_unique($seen), 'the same payslip came back on two pages');
    }

    public function test_the_breakdown_page_size_is_capped(): void
    {
        $this->slips(12);

        $this->assertCount(12, $this->detail('?per_page=100000')['breakdown']);
        $this->assertSame(100, $this->detail('?per_page=100000')['breakdown_meta']['per_page']);
    }

    /**
     * The register's search runs in the QUERY. A box over the ten rows on screen would answer
     * "no matches" for somebody four pages down — on the one screen whose use is finding a
     * particular person's line.
     */
    public function test_the_breakdown_search_finds_somebody_the_page_never_contained(): void
    {
        // Created LAST, because the breakdown is ordered by id ascending — so this payslip is on
        // the final page and page one cannot contain it.
        $this->slips(40);
        $needle = $this->payslip();
        $needle->employee->user->update(['name' => 'Zenaida Distinctive']);

        $page1 = $this->detail('?per_page=10');
        $this->assertNotContains(
            $needle->id,
            collect($page1['breakdown'])->pluck('id')->all(),
            'precondition: the payslip searched for is not on the page already loaded',
        );

        $found = $this->detail('?per_page=10&search=Zenaida');

        $this->assertCount(1, $found['breakdown']);
        $this->assertSame(1, $found['breakdown_meta']['total'], 'the total must follow the search');
    }

    /**
     * The run's own figures do NOT follow the search. They describe the payroll; narrowing the
     * table must not restate the register as one employee's pay.
     */
    public function test_a_search_narrows_the_table_but_not_the_run_totals(): void
    {
        $this->slips(20);

        $all = $this->detail('?per_page=10');
        $searched = $this->detail('?per_page=10&search=Person 1');

        $this->assertEqualsWithDelta(
            $all['total_gross'],
            $searched['total_gross'],
            0.01,
            'the register total must describe the run, not the filtered table',
        );
        $this->assertSame($all['disbursed_count'], $searched['disbursed_count']);
    }

    // --- the register totals ---------------------------------------------------------------

    /**
     * THE MONEY. 45 payslips at ₱1,000 gross; the screen shows ten of them. The figure at the
     * top of the page must be the run's, not the page's — ₱45,000, never ₱10,000.
     */
    public function test_the_register_totals_describe_the_run_not_the_page(): void
    {
        $this->slips(45);

        $detail = $this->detail('?per_page=10');

        $this->assertEqualsWithDelta(45000.0, $detail['total_gross'], 0.01, 'gross must cover the run');
        $this->assertEqualsWithDelta(40500.0, $detail['total_net'], 0.01, 'net must cover the run');
        $this->assertEqualsWithDelta(4500.0, $detail['total_deductions'], 0.01);
    }

    // --- can_lock ---------------------------------------------------------------------------

    /**
     * THE IRREVERSIBLE ONE. Locking seals a cycle so it can never be recomputed, and it is
     * offered only when every payslip is settled. One unsettled payslip, placed beyond the first
     * page, must still withhold it — a page of ten settled rows is not a settled payroll.
     */
    public function test_an_unsettled_payslip_beyond_the_first_page_still_blocks_locking(): void
    {
        $this->slips(30);                                        // all settled
        $this->payslip(['status' => 'pending', 'disbursed_amount' => null]); // the 31st, unsettled

        $detail = $this->detail('?per_page=10');

        $this->assertCount(10, $detail['breakdown'], 'precondition: the screen holds a page');
        $this->assertFalse($detail['can_lock'], 'a page of settled rows is not a settled run');
    }

    public function test_a_fully_settled_run_can_be_locked(): void
    {
        $this->slips(30);

        $this->assertTrue($this->detail('?per_page=10')['can_lock']);
    }

    public function test_an_empty_run_cannot_be_locked(): void
    {
        $this->assertFalse($this->detail()['can_lock']);
    }

    // --- what a correction left behind -------------------------------------------------------

    /**
     * The notices above the breakdown: money still owed after a correction, and money paid that
     * should not have been. Both were counted from the loaded rows, so both would have described
     * the page — on the screen HR reads to find out what is still outstanding.
     */
    public function test_the_correction_notices_count_the_whole_run(): void
    {
        $this->slips(30);
        // Two corrected UPWARD after payment: ₱150 still to pay between them.
        $this->payslip(['net_pay' => 1000, 'disbursed_amount' => 900]);
        $this->payslip(['net_pay' => 950, 'disbursed_amount' => 900]);
        // One corrected DOWNWARD after payment: ₱200 overpaid.
        $this->payslip(['net_pay' => 700, 'disbursed_amount' => 900]);

        $detail = $this->detail('?per_page=10');

        $this->assertSame(2, $detail['owing_count']);
        $this->assertEqualsWithDelta(150.0, $detail['owing_total'], 0.01);
        $this->assertSame(1, $detail['overpaid_count']);
        $this->assertEqualsWithDelta(200.0, $detail['overpaid_total'], 0.01);
    }

    /** A rounding artefact is not an unpaid balance — the SQL must agree with the PHP rounding. */
    public function test_a_sub_centavo_difference_is_not_treated_as_outstanding(): void
    {
        $this->payslip(['net_pay' => 900.004, 'disbursed_amount' => 900]);

        $detail = $this->detail();

        $this->assertSame(0, $detail['owing_count']);
        $this->assertTrue($detail['can_lock'], 'a rounding artefact must not block a lock');
    }

    public function test_the_disbursed_count_covers_the_whole_run(): void
    {
        $this->slips(25);
        $this->payslip(['status' => 'pending', 'disbursed_amount' => null]);

        $this->assertSame(25, $this->detail('?per_page=10')['disbursed_count']);
    }

    // --- the runs LIST must not carry any of this ---------------------------------------------

    /**
     * These are detail-view fields. The list draws a row per run, and a lock verdict or a policy
     * snapshot per row would put the whole payroll behind every line of the history.
     */
    public function test_the_runs_list_carries_no_detail_only_fields(): void
    {
        $this->slips(5);

        $row = $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/payroll/runs?per_page=5')->assertOk()->json('data.0');

        foreach (['breakdown', 'can_lock', 'policy_snapshot', 'owing_count'] as $field) {
            $this->assertArrayNotHasKey($field, $row, "the list must not carry `{$field}`");
        }
    }
}
