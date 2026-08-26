<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\DocumentRequest;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollInquiry;
use App\Modules\Hr\Models\Reimbursement;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The request queues serve ONE PAGE from the database.
 *
 * They are the tables with no ceiling: every employee adds to them and nobody deletes a settled
 * request, so "fetch all of it and let the browser sort it out" ends badly and ends quietly. All
 * three used to do exactly that.
 *
 * The security half matters more than the speed half. Each queue is scoped — HR sees everyone's,
 * an employee sees only their own — and that scope is applied before the filters. If the search
 * group were ever left ungrouped it would sit beside the ownership clause, and AND binds tighter
 * than OR: an employee searching their own claims would match on somebody else's row. The same
 * mistake in the employee directory leaked active staff into the former roster. Here it would
 * leak other people's requests, which is why every filter below is tested against it.
 */
class RequestQueuePagingTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $alice;

    private Employee $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->alice = $this->makeEmployee($dept, 'E-001', 'Alice Reyes');
        $this->bob = $this->makeEmployee($dept, 'E-002', 'Bob Santos');
    }

    private function makeEmployee(Department $dept, string $no, string $name): Employee
    {
        return Employee::create([
            'user_id' => User::factory()->create(['name' => $name])->id,
            'department_id' => $dept->id,
            'employee_no' => $no,
            'job_title' => 'Staff',
            'hire_date' => '2020-01-01',
            'employment_status' => 'regular',
            'base_salary' => 30000,
            'pay_frequency' => 'semi_monthly',
            'is_management' => false,
        ]);
    }

    private function claims(Employee $emp, int $count, string $status = 'pending', string $note = 'Client visit'): void
    {
        foreach (range(1, $count) as $i) {
            Reimbursement::create([
                'employee_id' => $emp->id,
                'category' => 'transport',
                'amount' => 1000 + $i,
                'expense_date' => '2026-03-05',
                'description' => "{$note} {$i}",
                'status' => $status,
            ]);
        }
    }

    /** Act as an employee (not HR) — the scoped half of every queue. */
    private function asEmployee(Employee $emp): static
    {
        return $this->actingAs($emp->user);
    }

    // --- paging -----------------------------------------------------------------------

    public function test_the_claims_queue_serves_one_page_and_states_the_total(): void
    {
        $this->claims($this->alice, 45);

        $hr = $this->hrUser();

        $first = $this->actingAs($hr)->getJson('/api/v1/hr/reimbursements?per_page=10')->assertOk();
        $first->assertJsonCount(10, 'data');
        $this->assertSame(45, $first->json('meta.total'));
        $this->assertSame(5, $first->json('meta.last_page'));

        $second = $this->actingAs($hr)->getJson('/api/v1/hr/reimbursements?per_page=10&page=2')->assertOk();
        $this->assertEmpty(
            array_intersect(
                collect($first->json('data'))->pluck('id')->all(),
                collect($second->json('data'))->pluck('id')->all(),
            ),
            'page 2 repeated page 1',
        );
    }

    /**
     * The invariant paging actually has to hold: walk the whole queue and every row appears
     * exactly once. This is what catches an unstable sort — `created_at` has second precision,
     * so rows filed in the same second tie, and a tied ORDER BY has no defined answer. The
     * database may then order page 2 differently from page 1, and a record shows up twice or
     * not at all. Cheap to miss, because the totals still look right.
     */
    public function test_walking_every_page_returns_each_row_exactly_once(): void
    {
        $this->claims($this->alice, 47);

        $hr = $this->hrUser();
        $seen = [];

        foreach (range(1, 5) as $page) {
            foreach ($this->actingAs($hr)
                ->getJson("/api/v1/hr/reimbursements?per_page=10&page={$page}")
                ->assertOk()->json('data') as $row) {
                $seen[] = $row['id'];
            }
        }

        $this->assertCount(47, $seen, 'a row was dropped or duplicated across the pages');
        $this->assertCount(47, array_unique($seen), 'the same row came back on two pages');
    }

    /** `?per_page=100000` would put the whole-table read straight back. */
    public function test_the_page_size_is_capped(): void
    {
        $this->claims($this->alice, 20);

        $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/reimbursements?per_page=100000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    // --- the ownership scope survives every filter ------------------------------------

    /**
     * THE ONE THAT MATTERS. An employee's own queue, searched for a word that only appears on
     * SOMEBODY ELSE'S rows. An ungrouped OR would return Bob's claims to Alice.
     */
    public function test_a_search_cannot_widen_an_employees_queue_to_somebody_elses(): void
    {
        $this->claims($this->alice, 5, 'pending', 'Alice trip');
        $this->claims($this->bob, 5, 'pending', 'Bob confidential trip');

        $response = $this->asEmployee($this->alice)
            ->getJson('/api/v1/hr/reimbursements?search=confidential')
            ->assertOk();

        $this->assertSame(0, $response->json('meta.total'), 'the search reached outside the scope');
        $response->assertJsonCount(0, 'data');
    }

    /** The same guard on the filer's NAME, which the HR-facing search also matches. */
    public function test_searching_a_colleagues_name_does_not_reach_their_claims(): void
    {
        $this->claims($this->bob, 4);

        $response = $this->asEmployee($this->alice)
            ->getJson('/api/v1/hr/reimbursements?search=Bob')
            ->assertOk();

        $this->assertSame(0, $response->json('meta.total'));
    }

    /** HR, by contrast, is meant to find a claim by who filed it. */
    public function test_hr_can_still_find_a_claim_by_the_filers_name(): void
    {
        $this->claims($this->alice, 3);
        $this->claims($this->bob, 4);

        $response = $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/reimbursements?search=Bob')
            ->assertOk();

        $this->assertSame(4, $response->json('meta.total'));
    }

    /** Paging must not widen it either — page 2 of an employee's queue is still theirs. */
    public function test_paging_an_employees_queue_never_reaches_another_employee(): void
    {
        $this->claims($this->alice, 25);
        $this->claims($this->bob, 25);

        $response = $this->asEmployee($this->alice)
            ->getJson('/api/v1/hr/reimbursements?per_page=10&page=2')
            ->assertOk();

        $this->assertSame(25, $response->json('meta.total'), 'the total must count only their own');
        foreach ($response->json('data') as $row) {
            $this->assertSame($this->alice->id, $row['employee']['id'] ?? $this->alice->id);
        }
    }

    // --- the status filter ------------------------------------------------------------

    /**
     * Applied in the browser, "Show pending" filtered the page. Applied in the query it filters
     * the queue — and the total has to follow, or the pager offers pages that do not exist.
     */
    public function test_the_status_filter_narrows_the_whole_queue(): void
    {
        $this->claims($this->alice, 30, 'pending');
        $this->claims($this->alice, 4, 'approved');

        $response = $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/reimbursements?per_page=10&status=approved')
            ->assertOk();

        $this->assertSame(4, $response->json('meta.total'));
        $response->assertJsonCount(4, 'data');
    }

    /** "all" is the absence of a filter, not a status nothing matches. */
    public function test_the_all_status_is_not_treated_as_a_filter(): void
    {
        $this->claims($this->alice, 12);

        $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/reimbursements?status=all')
            ->assertOk()
            ->assertJsonPath('meta.total', 12);
    }

    /**
     * The case a browser-side search cannot answer: a row that is not on the page in hand. It
     * used to report nothing found for a record that plainly exists.
     */
    public function test_a_search_finds_a_row_the_first_page_never_contained(): void
    {
        $this->claims($this->alice, 1, 'pending', 'Distinctive marker');
        $this->claims($this->alice, 40); // 40 NEWER claims — the queue is newest-first

        $hr = $this->hrUser();

        $page1 = $this->actingAs($hr)->getJson('/api/v1/hr/reimbursements?per_page=10')->assertOk();
        $this->assertStringNotContainsString(
            'Distinctive',
            json_encode($page1->json('data')),
            'precondition: the row searched for is not on the page already loaded',
        );

        $found = $this->actingAs($hr)
            ->getJson('/api/v1/hr/reimbursements?search=Distinctive')
            ->assertOk();

        $this->assertSame(1, $found->json('meta.total'));
    }

    // --- rows needing a decision sort to page 1 ---------------------------------------

    /**
     * The queues used to float pending rows to the top in the BROWSER. Once only one page is in
     * hand that would reorder the ten rows that happened to land on it — leaving the pending
     * ones scattered across pages 2 to 5, which is the opposite of what floating them was for.
     * The order moved into the query, so they are on page 1 whatever their date.
     */
    public function test_rows_still_needing_a_decision_come_first_across_the_whole_queue(): void
    {
        // The pending one is the OLDEST, so date alone would put it on the last page.
        $this->claims($this->alice, 1, 'pending', 'Oldest but unresolved');
        $this->claims($this->alice, 40, 'approved');

        $page1 = $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/reimbursements?per_page=10')
            ->assertOk();

        $this->assertSame(
            'pending',
            $page1->json('data.0.status'),
            'the row still needing a decision must lead the queue, not sit four pages back',
        );
    }

    /** Below the priority rows, the queue is still newest-first. */
    public function test_the_rest_of_the_queue_is_still_newest_first(): void
    {
        $this->claims($this->alice, 3, 'approved', 'Older');
        $this->claims($this->alice, 3, 'approved', 'Newer');

        $rows = $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/reimbursements?per_page=10')
            ->assertOk()
            ->json('data');

        $this->assertStringContainsString('Newer', $rows[0]['description']);
    }

    // --- item 5: the summary tiles come from the database ------------------------------

    /**
     * The finance screen's six figures used to be computed by filtering the full list in the
     * browser. Once the queue serves one page, that same code describes the PAGE while looking
     * exactly as authoritative — "₱x pending" meaning "pending among the ten rows on screen".
     * They are counted by the database now, so they describe the queue however it is paged.
     */
    public function test_the_summary_counts_the_whole_queue_not_a_page(): void
    {
        $this->claims($this->alice, 30, 'pending');   // 1001..1030
        $this->claims($this->bob, 4, 'approved');     // 1001..1004

        $summary = $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/reimbursements/summary')
            ->assertOk()
            ->json('data');

        $this->assertSame(30, $summary['pending']['count']);
        $this->assertSame(4, $summary['approved']['count']);

        // 1001 + … + 1030, and 1001 + … + 1004 — the sums, not just the counts.
        $this->assertEqualsWithDelta(30465.0, $summary['pending']['amount'], 0.01);
        $this->assertEqualsWithDelta(4010.0, $summary['approved']['amount'], 0.01);
    }

    /** It counts everybody's claims, so it is not an employee's to read. */
    public function test_the_summary_is_hr_only(): void
    {
        $this->claims($this->alice, 3);

        $this->asEmployee($this->alice)
            ->getJson('/api/v1/hr/reimbursements/summary')
            ->assertForbidden();
    }

    /** A status nobody has filed is absent rather than zero — the screen supplies the zero. */
    public function test_the_summary_omits_statuses_with_no_claims(): void
    {
        $this->claims($this->alice, 2, 'pending');

        $summary = $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/reimbursements/summary')->json('data');

        $this->assertArrayHasKey('pending', $summary);
        $this->assertArrayNotHasKey('paid', $summary);
    }

    /**
     * The payroll-inquiry screen puts a banner above the queue when something urgent is
     * outstanding. That was a scan of every row in the browser, which once one page is in hand
     * answers for the page and says nothing about the other forty. Urgent rows sort to the top,
     * so the question is answerable from the page it is asked on.
     */
    public function test_an_urgent_inquiry_leads_the_queue(): void
    {
        // The urgent one is the OLDEST, so date alone would bury it.
        PayrollInquiry::create([
            'employee_id' => $this->alice->id, 'subject' => 'Urgent underpayment',
            'topic' => 'deduction', 'message' => 'Short on rent', 'priority' => 'high',
            'status' => 'pending',
        ]);
        foreach (range(1, 30) as $i) {
            PayrollInquiry::create([
                'employee_id' => $this->alice->id, 'subject' => "Routine {$i}",
                'topic' => 'other', 'message' => 'Question', 'priority' => 'normal',
                'status' => 'pending',
            ]);
        }

        $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/payroll-inquiries?per_page=10')
            ->assertOk()
            ->assertJsonPath('data.0.subject', 'Urgent underpayment');
    }

    // --- the other two queues share the trait, so they share the guarantees -----------

    public function test_the_document_request_queue_pages_and_stays_scoped(): void
    {
        foreach (range(1, 24) as $i) {
            DocumentRequest::create([
                'employee_id' => $this->alice->id, 'type' => 'coe',
                'reason' => "Bank application {$i}", 'status' => 'pending',
            ]);
        }
        DocumentRequest::create([
            'employee_id' => $this->bob->id, 'type' => 'coe',
            'reason' => 'Bob private matter', 'status' => 'pending',
        ]);

        $hr = $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/document-requests?per_page=10')->assertOk();
        $this->assertSame(25, $hr->json('meta.total'));
        $hr->assertJsonCount(10, 'data');

        $mine = $this->asEmployee($this->alice)
            ->getJson('/api/v1/hr/me/document-requests?per_page=10&search=private')->assertOk();
        $this->assertSame(0, $mine->json('meta.total'), 'the search reached outside the scope');
    }

    public function test_the_change_request_queue_pages_and_stays_scoped(): void
    {
        foreach (range(1, 24) as $i) {
            \App\Modules\Hr\Models\ChangeRequest::create([
                'employee_id' => $this->alice->id, 'field' => 'phone',
                'old_value' => '0900', 'new_value' => "091700{$i}", 'status' => 'pending',
            ]);
        }
        \App\Modules\Hr\Models\ChangeRequest::create([
            'employee_id' => $this->bob->id, 'field' => 'bank_account',
            'old_value' => 'old', 'new_value' => 'confidential', 'status' => 'pending',
        ]);

        $hr = $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/change-requests?per_page=10')->assertOk();
        $this->assertSame(25, $hr->json('meta.total'));
        $hr->assertJsonCount(10, 'data');

        $mine = $this->asEmployee($this->alice)
            ->getJson('/api/v1/hr/me/change-requests?per_page=10&search=confidential')->assertOk();
        $this->assertSame(0, $mine->json('meta.total'), 'the search reached outside the scope');
    }

    public function test_the_payslip_request_queue_pages_and_stays_scoped(): void
    {
        $run = \App\Modules\Hr\Models\PayrollRun::create([
            'cycle_start' => '2026-08-01', 'cycle_end' => '2026-08-15',
            'frequency' => 'semi_monthly', 'type' => 'regular', 'status' => 'completed',
            'total_gross' => 0, 'total_deductions' => 0, 'total_net' => 0, 'employee_count' => 0,
        ]);
        $slip = \App\Modules\Hr\Models\Payslip::create([
            'payroll_run_id' => $run->id, 'employee_id' => $this->alice->id,
            'base_salary' => 30000, 'gross' => 1000, 'net_pay' => 900,
        ]);

        foreach (range(1, 24) as $i) {
            \App\Modules\Hr\Models\PayslipRequest::create([
                'employee_id' => $this->alice->id, 'payslip_id' => $slip->id,
                'reason' => "Copy for the bank {$i}", 'status' => 'pending',
            ]);
        }
        \App\Modules\Hr\Models\PayslipRequest::create([
            'employee_id' => $this->bob->id, 'payslip_id' => $slip->id,
            'reason' => 'Bob confidential matter', 'status' => 'pending',
        ]);

        $hr = $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/payslip-requests?per_page=10')->assertOk();
        $this->assertSame(25, $hr->json('meta.total'));
        $hr->assertJsonCount(10, 'data');

        $mine = $this->asEmployee($this->alice)
            ->getJson('/api/v1/hr/me/payslip-requests?per_page=10&search=confidential')->assertOk();
        $this->assertSame(0, $mine->json('meta.total'), 'the search reached outside the scope');
    }

    public function test_the_payroll_inquiry_queue_pages_and_stays_scoped(): void
    {
        foreach (range(1, 24) as $i) {
            PayrollInquiry::create([
                'employee_id' => $this->alice->id, 'subject' => "Deduction query {$i}",
                'topic' => 'deduction', 'message' => 'Please check', 'status' => 'pending',
            ]);
        }
        PayrollInquiry::create([
            'employee_id' => $this->bob->id, 'subject' => 'Bob salary secret',
            'topic' => 'other', 'message' => 'Private', 'status' => 'pending',
        ]);

        $hr = $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/payroll-inquiries?per_page=10')->assertOk();
        $this->assertSame(25, $hr->json('meta.total'));
        $hr->assertJsonCount(10, 'data');

        $mine = $this->asEmployee($this->alice)
            ->getJson('/api/v1/hr/me/payroll-inquiries?per_page=10&search=secret')->assertOk();
        $this->assertSame(0, $mine->json('meta.total'), 'the search reached outside the scope');
    }
}
