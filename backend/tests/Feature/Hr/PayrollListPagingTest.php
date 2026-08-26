<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\PaymentBatch;
use App\Modules\Hr\Models\PayrollRun;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The two payroll LISTS — run history and payment batches — served one page at a time.
 *
 * Run history is the slowest-growing list in the module (two a month) and the most expensive
 * row in it: each run carries four aggregate subqueries over its own payslips, so the cost per
 * row scales with HEADCOUNT even though the row count scales with time. A decade of runs at a
 * large company is a great deal of summing in order to render ten lines.
 *
 * Payment batches were the quieter bug. The endpoint already paginated — at twenty — and the
 * screen read page one and presented it as the whole list, so a run with more than twenty
 * batches simply hid the rest with nothing on screen to say so.
 *
 * The per-run BREAKDOWN is not here. It is a different shape of problem — the notices above it
 * are computed from the same rows — and is its own slice.
 */
class PayrollListPagingTest extends HrTestCase
{
    use RefreshDatabase;

    /** Runs are uniquely keyed on (cycle_start, cycle_end, frequency), so batches must not overlap. */
    private function runs(int $n, string $status = 'completed', int $from = 1): void
    {
        foreach (range($from, $from + $n - 1) as $i) {
            $start = date('Y-m-d', strtotime("2020-01-01 +{$i} months"));
            PayrollRun::create([
                'cycle_start' => $start,
                'cycle_end' => date('Y-m-d', strtotime("{$start} +14 days")),
                'frequency' => 'semi_monthly',
                'type' => 'regular',
                'status' => $status,
                'total_gross' => 1000 * $i,
                'total_deductions' => 100,
                'total_net' => 900 * $i,
                'employee_count' => 5,
            ]);
        }
    }

    // --- run history --------------------------------------------------------------------

    public function test_the_run_history_serves_one_page_and_states_the_total(): void
    {
        $this->runs(45);

        $hr = $this->hrUser();

        $first = $this->actingAs($hr)->getJson('/api/v1/hr/payroll/runs?per_page=10')->assertOk();
        $first->assertJsonCount(10, 'data');
        $this->assertSame(45, $first->json('meta.total'));
        $this->assertSame(5, $first->json('meta.last_page'));

        $second = $this->actingAs($hr)->getJson('/api/v1/hr/payroll/runs?per_page=10&page=2')->assertOk();
        $this->assertEmpty(
            array_intersect(
                collect($first->json('data'))->pluck('id')->all(),
                collect($second->json('data'))->pluck('id')->all(),
            ),
            'page 2 repeated page 1',
        );
    }

    /** Newest cycle first, which is the order the history modal has always shown. */
    public function test_the_run_history_leads_with_the_newest_cycle(): void
    {
        $this->runs(20);

        $rows = $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/payroll/runs?per_page=5')->assertOk()->json('data');

        $dates = array_column($rows, 'cycle_start');
        $sorted = $dates;
        rsort($sorted);
        $this->assertSame($sorted, $dates, 'the history must stay newest-first');
    }

    public function test_walking_every_page_returns_each_run_exactly_once(): void
    {
        $this->runs(37);

        $hr = $this->hrUser();
        $seen = [];
        foreach (range(1, 4) as $page) {
            foreach ($this->actingAs($hr)
                ->getJson("/api/v1/hr/payroll/runs?per_page=10&page={$page}")
                ->assertOk()->json('data') as $row) {
                $seen[] = $row['id'];
            }
        }

        $this->assertCount(37, $seen, 'a run was dropped or duplicated across the pages');
        $this->assertCount(37, array_unique($seen), 'the same run came back on two pages');
    }

    public function test_the_run_history_page_size_is_capped(): void
    {
        $this->runs(15);

        $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/payroll/runs?per_page=100000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_the_status_filter_narrows_the_whole_history(): void
    {
        $this->runs(20, 'completed');
        $this->runs(3, 'processing', from: 100);

        $response = $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/payroll/runs?per_page=10&status=processing')
            ->assertOk();

        $this->assertSame(3, $response->json('meta.total'));
        $response->assertJsonCount(3, 'data');
    }

    /**
     * The aggregate columns each row carries must survive paging — they are the register totals
     * the history table prints, and a page that dropped them would show blank money.
     */
    public function test_a_paged_run_still_carries_its_register_totals(): void
    {
        $this->runs(15);

        $row = $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/payroll/runs?per_page=5')->assertOk()->json('data.0');

        $this->assertArrayHasKey('total_net', $row);
        $this->assertArrayHasKey('employee_count', $row);
    }

    // --- "has this period been run?" -----------------------------------------------------

    /**
     * THE DANGEROUS ONE. The screen asks whether the current period has already been run, and
     * offers the Run button when the answer is no. That answer used to be a `find()` through the
     * run history the page happened to hold — so once the history is paged, a run sitting on
     * page 3 reads as "not run yet", and the button it puts back pays a whole company a second
     * time. It is an exact lookup now, and this proves it reaches past the first page.
     */
    public function test_a_run_is_found_for_its_cycle_however_deep_in_the_history_it_sits(): void
    {
        // The target is the OLDEST run, buried behind 60 newer ones.
        $this->runs(1, 'completed', from: 1);
        $target = PayrollRun::firstOrFail();
        $this->runs(60, 'completed', from: 2);

        $response = $this->actingAs($this->hrUser())->getJson(
            '/api/v1/hr/payroll/runs/for-cycle?' . http_build_query([
                'frequency' => $target->frequency,
                'cycle_start' => $target->cycle_start->toDateString(),
                'cycle_end' => $target->cycle_end->toDateString(),
            ]),
        )->assertOk();

        $this->assertSame($target->id, $response->json('data.id'), 'the run must be found by its cycle');
    }

    /** A period nobody has run comes back empty, which is what puts the Run button on screen. */
    public function test_an_unrun_cycle_comes_back_empty(): void
    {
        $this->runs(5);

        $this->actingAs($this->hrUser())->getJson(
            '/api/v1/hr/payroll/runs/for-cycle?' . http_build_query([
                'frequency' => 'semi_monthly',
                'cycle_start' => '2019-01-01',
                'cycle_end' => '2019-01-15',
            ]),
        )->assertOk()->assertJsonPath('data', null);
    }

    // --- payment batches -----------------------------------------------------------------

    /**
     * THE SILENT ONE. The endpoint already paged at twenty; the screen read page one and showed
     * it as the whole list. Twenty-five batches meant five invisible ones and no indication.
     */
    public function test_the_batch_list_reaches_past_the_old_twenty_row_ceiling(): void
    {
        $this->runs(1);
        $run = PayrollRun::firstOrFail();
        $creator = $this->hrUser();

        foreach (range(1, 25) as $i) {
            PaymentBatch::create([
                'payroll_run_id' => $run->id,
                'reference' => sprintf('PB-%04d', $i),
                'status' => 'draft',
                'total_amount' => 1000 + $i,
                'payslip_count' => 1,
                'created_by' => $creator->id,
            ]);
        }

        $default = $this->actingAs($creator)
            ->getJson("/api/v1/hr/payment-batches?payroll_run_id={$run->id}")->assertOk();
        $this->assertSame(25, $default->json('meta.total'), 'the total must state what is there');
        $default->assertJsonCount(20, 'data');

        // And the rest is now reachable rather than silently absent.
        $page2 = $this->actingAs($creator)
            ->getJson("/api/v1/hr/payment-batches?payroll_run_id={$run->id}&page=2")->assertOk();
        $page2->assertJsonCount(5, 'data');
    }

    /**
     * THE WRONG NUMBER. The dialog computed "still outstanding" as the run's net minus the sum
     * of the batches it held — and it held twenty. Twenty-five batches meant an outstanding
     * balance short by five payments, presented as fact on the screen whose job is to say what
     * is still owed.
     */
    public function test_the_paid_total_covers_every_batch_not_a_page(): void
    {
        $this->runs(1);
        $run = PayrollRun::firstOrFail();
        $creator = $this->hrUser();

        // 25 batches of ₱1,000 each — five of them beyond the page the screen used to read.
        foreach (range(1, 25) as $i) {
            PaymentBatch::create([
                'payroll_run_id' => $run->id,
                'reference' => sprintf('PB-%04d', $i),
                'status' => 'draft',
                'total_amount' => 1000,
                'payslip_count' => 1,
                'created_by' => $creator->id,
            ]);
        }

        $summary = $this->actingAs($creator)
            ->getJson("/api/v1/hr/payment-batches/summary?payroll_run_id={$run->id}")
            ->assertOk()->json('data');

        $this->assertSame(25, $summary['batch_count']);
        $this->assertEqualsWithDelta(
            25000.0,
            $summary['paid_total'],
            0.01,
            'the paid total must cover every batch — summing the page understates it by five',
        );
    }

    /** Scoped to its run, so one run's payments never count towards another's balance. */
    public function test_the_paid_total_is_scoped_to_its_run(): void
    {
        $this->runs(2);
        [$first, $second] = PayrollRun::orderBy('id')->get()->all();
        $creator = $this->hrUser();

        PaymentBatch::create([
            'payroll_run_id' => $first->id, 'reference' => 'PB-A', 'status' => 'draft',
            'total_amount' => 500, 'payslip_count' => 1, 'created_by' => $creator->id,
        ]);
        PaymentBatch::create([
            'payroll_run_id' => $second->id, 'reference' => 'PB-B', 'status' => 'draft',
            'total_amount' => 9000, 'payslip_count' => 1, 'created_by' => $creator->id,
        ]);

        $summary = $this->actingAs($creator)
            ->getJson("/api/v1/hr/payment-batches/summary?payroll_run_id={$first->id}")
            ->assertOk()->json('data');

        $this->assertEqualsWithDelta(500.0, $summary['paid_total'], 0.01);
        $this->assertSame(1, $summary['batch_count']);
    }

    public function test_the_batch_page_size_is_capped(): void
    {
        $this->runs(1);

        $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/payment-batches?per_page=100000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }
}
