<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\EarlyOutRequest;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmployeeLoan;
use App\Modules\Hr\Models\HalfDayRequest;
use App\Modules\Hr\Models\OvertimeRequest;
use App\Modules\Hr\Services\DashboardService;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * Every request type HR can decide must appear in the aggregates that claim to list them.
 *
 * THE FAILURE THIS GUARDS is silent by construction. Overtime, early-out, half-day and loan
 * requests all had working approve/reject endpoints and their own review page, and were absent
 * from the dashboard's Pending Approvals, the notification bell, and the nav badges. Nothing
 * errored: a request simply arrived, sat in a queue nobody was shown, and was found only if
 * somebody happened to open the right page. Adding the fifth request type without adding it here
 * would reproduce that exactly, so each surface is asserted per type rather than in aggregate.
 *
 * The badge assertions matter as much as the queue ones. Counts are keyed by NAV HREF and the
 * sidebar matches `navBadges[child.href]` by exact string, so a count written against a path that
 * is not a nav item is dropped without complaint — which is how the leave badge went missing for
 * as long as it did.
 */
class ApprovalQueueCoverageTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    /**
     * The hrefs the sidebar can actually match, mirroring `frontend/src/core/nav.ts`.
     *
     * Duplicated deliberately: the backend cannot import the nav, so without a copy here a stale
     * badge key stays invisible. If nav.ts changes, this list is the thing that fails.
     *
     * @var list<string>
     */
    private const NAV_HREFS = [
        '/teamora',
        '/teamora/attendance',
        '/teamora/benefits',
        '/teamora/claims',
        '/teamora/company/benefits',
        '/teamora/company/departments',
        '/teamora/company/faq',
        '/teamora/company/holidays',
        '/teamora/company/leave-types',
        '/teamora/company/rules',
        '/teamora/concerns',
        '/teamora/faq',
        '/teamora/finance/office-expenses',
        '/teamora/finance/payroll',
        '/teamora/finance/reimbursement',
        '/teamora/payslips',
        '/teamora/people/employees',
        '/teamora/people/recruitment',
        '/teamora/reports',
        '/teamora/requests/concern',
        '/teamora/requests/document',
        '/teamora/requests/payroll',
        '/teamora/requests/work-schedule',
        '/teamora/work-schedule',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-08-20 09:00', config('app.timezone_local')));

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'employment_status' => 'regular',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);
    }

    private function overtime(string $status = 'pending'): OvertimeRequest
    {
        return OvertimeRequest::create([
            'employee_id' => $this->employee->id, 'work_date' => '2026-08-18',
            'hours' => 3, 'time_from' => '17:00', 'time_to' => '20:00',
            'reason' => 'Month-end close', 'status' => $status,
            'reviewed_at' => $status === 'pending' ? null : now(),
        ]);
    }

    private function earlyOut(string $status = 'pending'): EarlyOutRequest
    {
        return EarlyOutRequest::create([
            'employee_id' => $this->employee->id, 'work_date' => '2026-08-19',
            'early_out_time' => '15:00', 'reason' => 'Family errand', 'status' => $status,
            'reviewed_at' => $status === 'pending' ? null : now(),
        ]);
    }

    private function halfDay(string $status = 'pending'): HalfDayRequest
    {
        return HalfDayRequest::create([
            'employee_id' => $this->employee->id, 'work_date' => '2026-08-17',
            'period' => 'morning', 'reason' => 'Clinic', 'status' => $status,
            'reviewed_at' => $status === 'pending' ? null : now(),
        ]);
    }

    private function loan(string $status = 'requested'): EmployeeLoan
    {
        return EmployeeLoan::create([
            'employee_id' => $this->employee->id, 'type' => 'loan',
            'principal' => 12000, 'per_period_amount' => 1000,
            'start_date' => '2026-09-01', 'status' => $status,
            'requested_at' => now(),
            'approved_at' => $status === 'requested' ? null : now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function hrFeed(): array
    {
        return app(NotificationService::class)->forUser($this->hrUser(), 50, 0);
    }

    /** @return list<string> */
    private function hrItemIds(): array
    {
        return array_column($this->hrFeed()['items'], 'id');
    }

    // --- the HR queue ---------------------------------------------------------------------------

    public function test_a_pending_overtime_request_reaches_hr(): void
    {
        $ot = $this->overtime();

        $this->assertContains("overtime-{$ot->id}", $this->hrItemIds());
    }

    public function test_a_pending_early_out_request_reaches_hr(): void
    {
        $eo = $this->earlyOut();

        $this->assertContains("earlyout-{$eo->id}", $this->hrItemIds());
    }

    /** A loan waits on HR to decide before the employee can even be asked to authorise it. */
    public function test_a_requested_loan_reaches_hr(): void
    {
        $loan = $this->loan();

        $this->assertContains("loan-{$loan->id}", $this->hrItemIds());
    }

    /** Decided items are not outstanding work and must not linger in the queue. */
    public function test_a_decided_request_is_not_in_the_hr_queue(): void
    {
        $ot = $this->overtime('approved');

        $this->assertNotContains("overtime-{$ot->id}", $this->hrItemIds());
    }

    // --- the badges -----------------------------------------------------------------------------

    /**
     * All four schedule request types are reviewed on one page, so one badge counts all four.
     * This was keyed to `/teamora/requests/leave`, which is not a nav item and not a page.
     */
    public function test_the_work_schedule_badge_counts_every_type_reviewed_there(): void
    {
        $this->overtime();
        $this->earlyOut();
        $this->halfDay();

        $nav = $this->hrFeed()['nav'];

        $this->assertSame(3, $nav['/teamora/requests/work-schedule'] ?? 0);
    }

    /** A count written against a path the sidebar cannot match is silently thrown away. */
    public function test_every_badge_key_is_a_real_nav_href(): void
    {
        $this->overtime();

        foreach (array_keys($this->hrFeed()['nav']) as $href) {
            $this->assertContains(
                $href,
                self::NAV_HREFS,
                "Badge count keyed to {$href}, which no nav item matches — the sidebar looks these "
                    . 'up by exact string, so this count never reaches a badge.',
            );
        }
    }

    /** Every link in the feed has to go somewhere; `/teamora/time-off` did not exist. */
    public function test_every_feed_href_is_a_real_page(): void
    {
        $this->overtime();
        $this->earlyOut();
        $this->loan();

        foreach ($this->hrFeed()['items'] as $item) {
            $this->assertNotSame('/teamora/time-off', $item['href']);
            $this->assertStringStartsWith('/teamora', $item['href']);
        }
    }

    // --- what the employee is told ----------------------------------------------------------

    /** @return list<string> */
    private function employeeItemIds(): array
    {
        $feed = app(NotificationService::class)->forUser($this->employee->user, 50, 0);

        return array_column($feed['items'], 'id');
    }

    public function test_the_employee_is_told_their_overtime_was_decided(): void
    {
        $ot = $this->overtime('approved');

        $this->assertContains("overtime-{$ot->id}", $this->employeeItemIds());
    }

    public function test_the_employee_is_told_their_early_out_was_decided(): void
    {
        $eo = $this->earlyOut('rejected');

        $this->assertContains("earlyout-{$eo->id}", $this->employeeItemIds());
    }

    /**
     * THE ONE WITH A CONSEQUENCE. Approving a loan does not start the deduction — the employee has
     * to authorise it themselves (Art. 113). If nothing tells them, the loan waits forever.
     */
    public function test_the_employee_is_told_their_loan_needs_authorising(): void
    {
        $loan = $this->loan('pending_authorization');

        $feed = app(NotificationService::class)->forUser($this->employee->user, 50, 0);
        $item = collect($feed['items'])->firstWhere('id', "loan-{$loan->id}");

        $this->assertNotNull($item, 'the employee was never told the loan was approved');
        $this->assertStringContainsString('authorisation', $item['title']);
    }

    // --- the dashboard ------------------------------------------------------------------------

    /** `leavers` was hardcoded to 0 with a note that stopped being true when MF-18 shipped. */
    public function test_the_headcount_chart_counts_real_leavers(): void
    {
        app(EmployeeService::class)->separate($this->employee, [
            'separation_type' => 'voluntary',
            'separation_date' => '2026-08-10',
        ]);

        $chart = collect(app(DashboardService::class)->hrStats()['headcount_chart']);
        $august = $chart->firstWhere('month', 'Aug');

        $this->assertSame(1, $august['leavers'], 'a separation in this month is a leaver');
        $this->assertSame(-1, $august['net'], 'and net is joiners minus leavers');
    }

    /** A separation is the biggest change a headcount has, and the feed could not show one. */
    public function test_recent_activity_includes_a_separation(): void
    {
        app(EmployeeService::class)->separate($this->employee, [
            'separation_type' => 'voluntary',
            'separation_date' => '2026-08-10',
        ]);

        $types = array_column(app(DashboardService::class)->hrStats()['recent_activity'], 'type');

        $this->assertContains('separation', $types);
    }

    public function test_recent_activity_includes_schedule_decisions(): void
    {
        $this->overtime('approved');
        $this->earlyOut('rejected');

        $types = array_column(app(DashboardService::class)->hrStats()['recent_activity'], 'type');

        $this->assertContains('overtime', $types);
        $this->assertContains('early_out', $types);
    }

    // --- the loans list the queue reads --------------------------------------------------------

    /** Without a cross-employee list, a requested loan was reachable only from its own record. */
    public function test_hr_can_list_requested_loans_across_employees(): void
    {
        $loan = $this->loan();
        $this->loan('active');

        $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/loans?status=requested')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $loan->id)
            ->assertJsonPath('data.0.employee.id', $this->employee->id);
    }

    public function test_an_employee_cannot_list_everyones_loans(): void
    {
        $this->loan();

        $this->actingAs($this->employee->user)
            ->getJson('/api/v1/hr/loans')
            ->assertForbidden();
    }
}
