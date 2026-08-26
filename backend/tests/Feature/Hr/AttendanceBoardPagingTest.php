<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * The attendance board and the open-shift queue, served one page at a time.
 *
 * These two are not queues and cannot be paged the way the queues are. Every row's status is
 * DERIVED — from the employee's effective schedule, the day's holidays and suspensions, an
 * approved leave, whether the day has ended, an approved overtime request. None of it is a
 * column, so none of it can become a WHERE clause without writing schedule resolution a second
 * time in SQL and letting the two answers drift apart.
 *
 * So the order is compute → filter → slice, and the filters are tested against the WHOLE roster
 * rather than the page: "show me everyone still not checked in" has to mean the company, not the
 * twenty-five rows that happened to be on screen.
 *
 * What this does not do is make the server's work smaller. The roster is still walked in full,
 * because that is what "today, for everyone" means. What it removes is the payload — and this
 * board polls every sixty seconds.
 */
class AttendanceBoardPagingTest extends HrTestCase
{
    use RefreshDatabase;

    /** A past weekday, so the day has ended and absences are real. */
    private const DATE = '2026-06-16';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-06-18 09:00', config('app.timezone_local')));

        WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 0,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 0, 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get();
        Department::create(['name' => 'Ops', 'code' => 'OPS']);
    }

    private function employee(string $name): Employee
    {
        static $n = 0;
        $n++;

        return Employee::create([
            'user_id' => User::factory()->create(['name' => $name])->id,
            'department_id' => Department::first()->id,
            'employee_no' => sprintf('E-%03d', $n),
            'job_title' => 'Staff', 'hire_date' => '2020-01-01', 'employment_status' => 'regular',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
            'work_schedule_id' => WorkSchedule::first()->id,
        ]);
    }

    /** Someone who turned up, at $time. No row at all = absent on a past workday. */
    private function present(Employee $e, string $time = '08:00'): void
    {
        $tz = config('app.timezone_local');
        Attendance::create([
            'employee_id' => $e->id, 'work_date' => self::DATE,
            'time_in' => Carbon::parse(self::DATE . " {$time}", $tz)->utc(),
            'time_out' => Carbon::parse(self::DATE . ' 17:00', $tz)->utc(),
            'status' => $time > '08:00' ? 'late' : 'present',
            'lunch_minutes' => 60, 'source' => Attendance::SOURCE_MANUAL,
        ]);
    }

    private function board(string $query = ''): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/attendance?date=' . self::DATE . $query)
            ->assertOk();
    }

    // --- paging -----------------------------------------------------------------------

    public function test_the_board_serves_one_page_and_states_the_roster_size(): void
    {
        foreach (range(1, 45) as $i) {
            $this->present($this->employee(sprintf('Person %02d', $i)));
        }

        $first = $this->board('&per_page=10');
        $first->assertJsonCount(10, 'data');
        $this->assertSame(45, $first->json('meta.total'), 'the total must count the roster');
        $this->assertSame(5, $first->json('meta.last_page'));

        $second = $this->board('&per_page=10&page=2');
        $this->assertEmpty(
            array_intersect(
                collect($first->json('data'))->pluck('employee_id')->all(),
                collect($second->json('data'))->pluck('employee_id')->all(),
            ),
            'page 2 repeated page 1',
        );
    }

    public function test_walking_every_page_returns_each_employee_exactly_once(): void
    {
        foreach (range(1, 37) as $i) {
            $this->present($this->employee(sprintf('Person %02d', $i)));
        }

        $seen = [];
        foreach (range(1, 4) as $page) {
            foreach ($this->board("&per_page=10&page={$page}")->json('data') as $row) {
                $seen[] = $row['employee_id'];
            }
        }

        $this->assertCount(37, $seen, 'an employee was dropped or duplicated across the pages');
        $this->assertCount(37, array_unique($seen), 'the same employee came back on two pages');
    }

    public function test_the_board_page_size_is_capped(): void
    {
        $this->present($this->employee('Solo'));

        $this->board('&per_page=100000')->assertJsonPath('meta.per_page', 200);
    }

    // --- the filters describe the ROSTER ------------------------------------------------

    /**
     * THE ONE THAT MATTERS. Thirty people present, one absent — and the absent one is last
     * alphabetically, so they are nowhere near the first page. Filtering to "absent" has to find
     * them, which a browser-side filter over twenty-five rows could not.
     */
    public function test_the_status_filter_searches_the_roster_not_the_page(): void
    {
        foreach (range(1, 30) as $i) {
            $this->present($this->employee(sprintf('Aaa Person %02d', $i)));
        }
        $this->employee('Zzz Nobody'); // no attendance row on a past workday = absent

        $unfiltered = $this->board('&per_page=10');
        $this->assertNotContains(
            'Zzz Nobody',
            collect($unfiltered->json('data'))->pluck('name')->all(),
            'precondition: the absent employee is not on the page already loaded',
        );

        $absent = $this->board('&per_page=10&status=absent');
        $this->assertSame(1, $absent->json('meta.total'), 'the filter must reach the whole roster');
        $this->assertSame('Zzz Nobody', $absent->json('data.0.name'));
    }

    /** Timing is derived from the punch against the employee's own shift — also not a column. */
    public function test_the_timing_filter_searches_the_roster_not_the_page(): void
    {
        foreach (range(1, 30) as $i) {
            $this->present($this->employee(sprintf('Aaa Person %02d', $i)), '08:00');
        }
        $this->present($this->employee('Zzz Latecomer'), '09:30');

        $late = $this->board('&per_page=10&timing=late');

        $this->assertSame(1, $late->json('meta.total'));
        $this->assertSame('Zzz Latecomer', $late->json('data.0.name'));
    }

    public function test_the_search_finds_somebody_the_first_page_never_contained(): void
    {
        foreach (range(1, 30) as $i) {
            $this->present($this->employee(sprintf('Aaa Person %02d', $i)));
        }
        $this->present($this->employee('Zenaida Distinctive'));

        $found = $this->board('&per_page=10&search=Distinctive');

        $this->assertSame(1, $found->json('meta.total'));
    }

    /** "all" is the absence of a filter, not a status nobody has. */
    public function test_the_all_values_are_not_treated_as_filters(): void
    {
        foreach (range(1, 5) as $i) {
            $this->present($this->employee("Person {$i}"));
        }

        $this->assertSame(5, $this->board('&status=all&timing=all')->json('meta.total'));
    }

    /**
     * The board is sorted by surname. Paging must not disturb that — a page that re-sorted
     * within itself would put the same person in a different place depending on the page size.
     */
    public function test_the_roster_stays_in_surname_order_across_pages(): void
    {
        foreach (['Delta', 'Alpha', 'Echo', 'Bravo', 'Charlie'] as $name) {
            $this->present($this->employee($name));
        }

        $names = collect($this->board('&per_page=10')->json('data'))->pluck('name')->all();
        $sorted = $names;
        sort($sorted);

        $this->assertSame($sorted, $names);
    }
}
