<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\EarlyOutRequest;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\HalfDayRequest;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\OvertimeRequest;
use App\Modules\Hr\Models\WorkRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The five queues on the work-schedule page, all served one page at a time.
 *
 * This page was the worst of them: five tables, each downloading every request of its kind that
 * anyone had ever filed, on one screen. They are the fastest-growing tables in the module —
 * overtime and half-days are weekly events for a whole workforce.
 *
 * The guarantees are the same three the other queues have, and they are tested per queue rather
 * than once for the trait, because each declares its own searchable columns and its own idea of
 * which day it is about:
 *
 *   1. paging returns each row exactly once — an unstable sort silently duplicates and drops;
 *   2. the ownership scope survives search and paging — HR sees all, an employee sees theirs;
 *   3. rows still needing a decision lead, whatever their date.
 */
class WorkScheduleQueuePagingTest extends HrTestCase
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

    /** A work date `$i` days back, so every row in a batch has a distinct one. */
    private function day(int $i): string
    {
        return date('Y-m-d', strtotime("2026-06-01 -{$i} days"));
    }

    private function overtime(Employee $e, int $n, string $status = 'pending', string $reason = 'Release push', int $from = 1): void
    {
        foreach (range($from, $from + $n - 1) as $i) {
            OvertimeRequest::create([
                'employee_id' => $e->id, 'work_date' => $this->day($i), 'hours' => 2,
                'reason' => "{$reason} {$i}", 'status' => $status,
            ]);
        }
    }

    private function earlyOut(Employee $e, int $n, string $status = 'pending', string $reason = 'Dental', int $from = 1): void
    {
        foreach (range($from, $from + $n - 1) as $i) {
            EarlyOutRequest::create([
                'employee_id' => $e->id, 'work_date' => $this->day($i), 'early_out_time' => '15:00',
                'reason' => "{$reason} {$i}", 'status' => $status,
            ]);
        }
    }

    private function halfDay(Employee $e, int $n, string $status = 'pending', string $reason = 'Clinic', int $from = 1): void
    {
        foreach (range($from, $from + $n - 1) as $i) {
            HalfDayRequest::create([
                'employee_id' => $e->id, 'work_date' => $this->day($i), 'period' => 'AM',
                'reason' => "{$reason} {$i}", 'status' => $status,
            ]);
        }
    }

    private function workRequest(Employee $e, int $n, string $status = 'pending', string $reason = 'Rest day cover', int $from = 1): void
    {
        foreach (range($from, $from + $n - 1) as $i) {
            WorkRequest::create([
                'employee_id' => $e->id, 'type' => 'rest_day', 'work_date' => $this->day($i),
                'requested_at' => now(), 'reason' => "{$reason} {$i}", 'status' => $status,
            ]);
        }
    }

    private function leave(Employee $e, int $n, string $status = 'pending', string $reason = 'Family trip', int $from = 1): void
    {
        $type = LeaveType::firstOrCreate(
            ['name' => 'Vacation Leave'],
            ['code' => 'VL', 'days_per_year' => 15, 'is_paid' => true, 'requires_approval' => true],
        );

        foreach (range($from, $from + $n - 1) as $i) {
            LeaveRequest::create([
                'employee_id' => $e->id, 'leave_type_id' => $type->id,
                'start_date' => $this->day($i), 'end_date' => $this->day($i), 'days' => 1,
                'reason' => "{$reason} {$i}", 'status' => $status,
            ]);
        }
    }

    private function asEmployee(Employee $emp): static
    {
        return $this->actingAs($emp->user);
    }

    /** @return list<array{0:string, 1:string, 2:callable}> url, a word only its rows carry, seeder */
    public static function queues(): array
    {
        return [
            'overtime' => ['/api/v1/hr/overtime-requests', 'overtime'],
            'early-out' => ['/api/v1/hr/early-out-requests', 'earlyOut'],
            'half-day' => ['/api/v1/hr/half-day-requests', 'halfDay'],
            'work request' => ['/api/v1/hr/work-requests', 'workRequest'],
            'leave' => ['/api/v1/hr/leave-requests', 'leave'],
        ];
    }

    // --- 1. paging returns each row exactly once ---------------------------------------

    #[DataProvider('queues')]
    public function test_walking_every_page_returns_each_row_exactly_once(string $url, string $seeder): void
    {
        $this->{$seeder}($this->alice, 47);

        $hr = $this->hrUser();
        $seen = [];

        foreach (range(1, 5) as $page) {
            $body = $this->actingAs($hr)->getJson("{$url}?per_page=10&page={$page}")->assertOk();
            foreach ($body->json('data') as $row) {
                $seen[] = $row['id'];
            }
        }

        $this->assertCount(47, $seen, 'a row was dropped or duplicated across the pages');
        $this->assertCount(47, array_unique($seen), 'the same row came back on two pages');
    }

    #[DataProvider('queues')]
    public function test_the_total_counts_the_queue_and_the_page_size_is_capped(string $url, string $seeder): void
    {
        $this->{$seeder}($this->alice, 24);

        $first = $this->actingAs($this->hrUser())->getJson("{$url}?per_page=10")->assertOk();
        $this->assertSame(24, $first->json('meta.total'));
        $this->assertSame(3, $first->json('meta.last_page'));
        $first->assertJsonCount(10, 'data');

        $this->actingAs($this->hrUser())
            ->getJson("{$url}?per_page=100000")
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    // --- 2. the ownership scope survives every filter ----------------------------------

    /**
     * An employee's own queue, searched for a word that only appears on SOMEBODY ELSE'S rows.
     * An ungrouped OR would sit beside the ownership clause and return the colleague's.
     */
    #[DataProvider('queues')]
    public function test_a_search_cannot_widen_an_employees_queue(string $url, string $seeder): void
    {
        $this->{$seeder}($this->alice, 4, 'pending', 'Alice ordinary');
        $this->{$seeder}($this->bob, 4, 'pending', 'Bob confidential', from: 50);

        $response = $this->asEmployee($this->alice)->getJson("{$url}?search=confidential")->assertOk();

        $this->assertSame(0, $response->json('meta.total'), 'the search reached outside the scope');
    }

    /**
     * The same guard on the filer's NAME, which the HR-facing search also matches.
     */
    #[DataProvider('queues')]
    public function test_searching_a_colleagues_name_does_not_reach_their_rows(string $url, string $seeder): void
    {
        $this->{$seeder}($this->bob, 4);

        $this->assertSame(
            0,
            $this->asEmployee($this->alice)->getJson("{$url}?search=Bob")->assertOk()->json('meta.total'),
        );

        // HR, by contrast, is meant to find a request by who filed it.
        $this->assertSame(
            4,
            $this->actingAs($this->hrUser())->getJson("{$url}?search=Bob")->assertOk()->json('meta.total'),
        );
    }

    #[DataProvider('queues')]
    public function test_paging_an_employees_queue_never_reaches_another_employee(string $url, string $seeder): void
    {
        $this->{$seeder}($this->alice, 25);
        $this->{$seeder}($this->bob, 25, 'pending', 'Filed', from: 50);

        $this->assertSame(
            25,
            $this->asEmployee($this->alice)->getJson("{$url}?per_page=10&page=2")->assertOk()->json('meta.total'),
            'the total must count only their own',
        );
    }

    // --- 3. rows needing a decision lead ------------------------------------------------

    /**
     * The screens used to float pending rows to the top in the browser, which once one page is
     * in hand reorders only the ten that landed on it. The order moved into the query.
     */
    #[DataProvider('queues')]
    public function test_rows_still_needing_a_decision_come_first(string $url, string $seeder): void
    {
        // The pending row is seeded FIRST and dated LAST: oldest work_date (90 days back) and
        // lowest id. Both of those matter, because both are sort keys — every other ordering
        // this queue could fall back on puts it at the bottom, so reaching page 1 can only be
        // the priority rung doing it.
        //
        // Two earlier versions of this test were worthless and both passed: the first dated the
        // pending row NEWEST, where date alone leads; the second still created it last, where
        // the id tiebreaker leads. Neither could fail with the ordering deleted.
        $this->{$seeder}($this->alice, 1, 'pending', 'Still waiting', from: 90);
        $this->{$seeder}($this->alice, 40, 'approved');

        $rows = $this->actingAs($this->hrUser())->getJson("{$url}?per_page=10")->assertOk()->json('data');

        $this->assertSame('pending', $rows[0]['status'], 'the undecided row must lead the queue');
        $this->assertSame(
            'approved',
            $rows[1]['status'],
            'precondition: the rest of page 1 is the decided rows it had to jump',
        );
    }

    // --- the status filter --------------------------------------------------------------

    #[DataProvider('queues')]
    public function test_the_status_filter_narrows_the_whole_queue(string $url, string $seeder): void
    {
        $this->{$seeder}($this->alice, 30, 'pending');
        $this->{$seeder}($this->bob, 4, 'approved', from: 50);

        $response = $this->actingAs($this->hrUser())->getJson("{$url}?per_page=10&status=approved")->assertOk();

        $this->assertSame(4, $response->json('meta.total'));
        $response->assertJsonCount(4, 'data');
    }
}
