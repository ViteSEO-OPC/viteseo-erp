<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The employee directory reads one page from the database, not the whole roster from memory.
 *
 * It used to serve the first hundred people and let the browser search, filter by department and
 * page through them. Three things were wrong with that and all three are load-bearing here: the
 * hundred-and-first employee was unreachable by any means, a search for them said "no employees
 * match your filters" rather than admitting it had only looked at a hundred, and the header count
 * described the download rather than the company.
 *
 * The picker dropdowns had the same ceiling for a worse reason — they cannot page at all, so
 * employee 101 simply could not be chosen as anyone's manager. They read their own slim endpoint
 * now, and it is not paged.
 */
class EmployeeDirectoryPagingTest extends HrTestCase
{
    use RefreshDatabase;

    private Department $ops;

    private Department $sales;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ops = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->sales = Department::create(['name' => 'Sales', 'code' => 'SLS']);
    }

    private function makeEmployee(string $name, array $attrs = []): Employee
    {
        static $n = 0;
        $n++;

        return Employee::create([
            'user_id' => User::factory()->create(['name' => $name])->id,
            'department_id' => $attrs['department_id'] ?? $this->ops->id,
            'employee_no' => $attrs['employee_no'] ?? sprintf('E-%03d', $n),
            'job_title' => $attrs['job_title'] ?? 'Staff',
            'hire_date' => '2021-01-01',
            'employment_status' => $attrs['employment_status'] ?? 'regular',
            'base_salary' => 25000,
            'pay_frequency' => 'semi_monthly',
            'is_management' => $attrs['is_management'] ?? false,
            // The role the departments modal buckets people under; null for ordinary staff.
            'employee_role' => $attrs['employee_role'] ?? null,
        ]);
    }

    private function seedPeople(int $count): void
    {
        foreach (range(1, $count) as $i) {
            $this->makeEmployee("Person {$i}");
        }
    }

    // --- paging -----------------------------------------------------------------------

    /**
     * THE ONE THAT MATTERS. 120 employees against the old hard-coded 100: everyone past that
     * point was invisible, and nothing on screen said so.
     */
    public function test_the_directory_reaches_past_the_old_hundred_row_ceiling(): void
    {
        $this->seedPeople(120);

        $hr = $this->hrUser();

        $first = $this->actingAs($hr)->getJson('/api/v1/hr/employees?per_page=12')->assertOk();
        $first->assertJsonCount(12, 'data');
        $this->assertSame(120, $first->json('meta.total'), 'the total must count the company');
        $this->assertSame(10, $first->json('meta.last_page'));

        // Page 10 is entirely inside the region the old cap could not reach.
        $last = $this->actingAs($hr)->getJson('/api/v1/hr/employees?per_page=12&page=10')->assertOk();
        $last->assertJsonCount(12, 'data');

        $firstIds = collect($first->json('data'))->pluck('id')->all();
        $lastIds = collect($last->json('data'))->pluck('id')->all();
        $this->assertEmpty(array_intersect($firstIds, $lastIds), 'page 10 repeated page 1');
    }

    /** `?per_page=100000` would turn the directory back into the whole-table read. */
    public function test_the_page_size_is_capped(): void
    {
        $this->seedPeople(30);

        $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/employees?per_page=100000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    // --- filters reach the query ------------------------------------------------------

    /**
     * A department filter applied in the browser narrows the page it was given. Applied in the
     * query it narrows the company — and the TOTAL has to follow it, or the pager offers pages
     * the result no longer has.
     */
    public function test_the_department_filter_narrows_the_whole_result_not_the_page(): void
    {
        $this->seedPeople(40);
        $this->makeEmployee('Sales Only One', ['department_id' => $this->sales->id]);
        $this->makeEmployee('Sales Only Two', ['department_id' => $this->sales->id]);

        $response = $this->actingAs($this->hrUser())
            ->getJson("/api/v1/hr/employees?per_page=12&department={$this->sales->id}")
            ->assertOk();

        $this->assertSame(2, $response->json('meta.total'));
        $response->assertJsonCount(2, 'data');
    }

    public function test_the_employment_status_filter_narrows_the_whole_result(): void
    {
        $this->seedPeople(20);
        $this->makeEmployee('Probie', ['employment_status' => 'probationary']);

        $response = $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/employees?per_page=12&employment_status=probationary')
            ->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('Probie', $response->json('data.0.name'));
    }

    /** An unrecognised value is ignored rather than silently returning nobody. */
    public function test_an_unknown_employment_status_is_ignored(): void
    {
        $this->seedPeople(5);

        $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/employees?employment_status=not_a_status')
            ->assertOk()
            ->assertJsonPath('meta.total', 5);
    }

    /**
     * Search and the filters have to compose. If the search group is AND'd wrongly against the
     * department clause, a search inside a department returns the whole department — or the
     * whole company.
     */
    public function test_search_and_department_compose(): void
    {
        $this->seedPeople(30);
        $this->makeEmployee('Maria Santos', ['department_id' => $this->sales->id]);
        $this->makeEmployee('Maria Cruz', ['department_id' => $this->ops->id]);

        $response = $this->actingAs($this->hrUser())
            ->getJson("/api/v1/hr/employees?search=Maria&department={$this->sales->id}")
            ->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('Maria Santos', $response->json('data.0.name'));
    }

    /**
     * A search that only matches somebody deep in the roster must find them. This is the case
     * the browser-side search could not answer: it reported "no employees match your filters"
     * for a person it had never been given.
     */
    public function test_a_search_finds_somebody_the_first_page_never_contained(): void
    {
        $needle = $this->makeEmployee('Zenaida Distinctive');
        $this->seedPeople(150); // 150 NEWER employees, and the directory is newest-first

        $hr = $this->hrUser();

        $page1 = $this->actingAs($hr)->getJson('/api/v1/hr/employees?per_page=12')->assertOk();
        $this->assertNotContains(
            $needle->id,
            collect($page1->json('data'))->pluck('id')->all(),
            'precondition: the person searched for is not on the page already loaded',
        );

        $found = $this->actingAs($hr)
            ->getJson('/api/v1/hr/employees?search=Zenaida')
            ->assertOk();

        $this->assertSame(1, $found->json('meta.total'));
        $this->assertSame($needle->id, $found->json('data.0.id'));
    }

    // --- the departments list must not carry the whole company ------------------------

    /**
     * Departments are bounded — a company has tens of them. The EAGER LOAD was not: it pulled
     * every employee in every department with their user record, so a list of departments cost
     * one payload per person in the company and grew with headcount rather than with department
     * count.
     *
     * DepartmentResource reads one thing from that relation — the employees who hold a role —
     * so that is all it may load.
     */
    public function test_the_departments_list_only_loads_employees_who_hold_a_role(): void
    {
        // Forty ordinary staff in Ops, and two with a role.
        $this->seedPeople(40);
        $this->makeEmployee('Team Lead', ['employee_role' => 'lead']);
        $this->makeEmployee('Supervisor', ['employee_role' => 'supervisor']);

        // Asserted on what was LOADED, not on what the response prints. The resource already
        // filters to role-holders, so the payload looks identical either way — the cost is the
        // hydration behind it, and only the relation shows that.
        $ops = app(\App\Modules\Hr\Services\DepartmentService::class)
            ->list()
            ->getCollection()
            ->firstWhere('code', 'OPS');

        $this->assertCount(
            2,
            $ops->employees,
            'the department must load its role-holders, not its whole headcount',
        );
    }

    /** And it must still carry them correctly — the guard above is satisfied by loading nothing. */
    public function test_the_departments_list_still_names_its_role_holders(): void
    {
        $this->makeEmployee('Ana Lead', ['employee_role' => 'lead']);

        $ops = collect(
            $this->actingAs($this->hrUser())->getJson('/api/v1/hr/departments')->json('data'),
        )->firstWhere('code', 'OPS');

        $this->assertSame('Ana Lead', $ops['assignments'][0]['name']);
        $this->assertSame('lead', $ops['assignments'][0]['role']);
    }

    // --- the picker options endpoint --------------------------------------------------

    /**
     * The dropdowns cannot page, so they must not be served a page. Employee 101 could not be
     * chosen as anyone's manager while they read the directory endpoint.
     */
    public function test_the_options_endpoint_is_not_capped(): void
    {
        $this->seedPeople(150);

        $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/employees/options')
            ->assertOk()
            ->assertJsonCount(150, 'data');
    }

    /** Four fields — id and name to render, plus the two the eligibility picker filters on. */
    public function test_the_options_payload_carries_only_what_a_picker_needs(): void
    {
        $this->makeEmployee('Ana Reyes', ['is_management' => true, 'employment_status' => 'contract']);

        $option = $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/employees/options')
            ->assertOk()
            ->json('data.0');

        $this->assertSame(
            ['id', 'name', 'employment_status', 'is_management'],
            array_keys($option),
        );
        $this->assertSame('Ana Reyes', $option['name']);
        $this->assertTrue($option['is_management']);
    }

    /** Named, so a picker reads like a list of people rather than a list of ids. */
    public function test_the_options_are_ordered_by_name(): void
    {
        $this->makeEmployee('Zara Cruz');
        $this->makeEmployee('Ana Bautista');
        $this->makeEmployee('Miguel Torres');

        $names = collect(
            $this->actingAs($this->hrUser())->getJson('/api/v1/hr/employees/options')->json('data'),
        )->pluck('name')->all();

        $this->assertSame(['Ana Bautista', 'Miguel Torres', 'Zara Cruz'], $names);
    }

    /** `options` must not be read as an employee id by the {employee} route beside it. */
    public function test_the_options_route_is_not_shadowed_by_the_show_route(): void
    {
        $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/employees/options')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }
}
