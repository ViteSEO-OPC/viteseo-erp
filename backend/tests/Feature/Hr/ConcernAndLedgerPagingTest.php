<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Concern;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\OfficeExpense;
use App\Modules\Hr\Models\Requisition;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Concerns, the office-expense ledger and the requisition board, served one page at a time.
 *
 * The concern queue is the one that needed care. ConcernResource already refuses to name whoever
 * filed an anonymous concern — but a SEARCH that matched the reporter's name would hand the same
 * fact back through a different door: type a colleague's name into the box and whatever anonymous
 * concerns come back are theirs. The identity is inferred from which rows match, and nothing in
 * the response has to name them for that to work.
 */
class ConcernAndLedgerPagingTest extends HrTestCase
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
            'department_id' => $dept->id, 'employee_no' => $no, 'job_title' => 'Staff',
            'hire_date' => '2020-01-01', 'employment_status' => 'regular',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);
    }

    private function concern(Employee $e, array $attrs = []): Concern
    {
        static $n = 0;
        $n++;

        return Concern::create(array_merge([
            'employee_id' => $e->id,
            'reference_no' => sprintf('CN-%04d', $n),
            'category' => 'workplace',
            'description' => "Something happened {$n}",
            'is_anonymous' => false,
            'status' => 'pending',
            'submitted_at' => now()->subDays($n),
        ], $attrs));
    }

    // --- the anonymity guard ------------------------------------------------------------

    /**
     * THE ONE THAT MATTERS. HR searches a name; the anonymous concern that person filed must
     * not come back. If it does, the box has become an identity lookup — regardless of what the
     * payload does or does not say.
     */
    public function test_searching_a_name_does_not_surface_their_anonymous_concern(): void
    {
        $this->concern($this->bob, ['is_anonymous' => true, 'description' => 'Filed in confidence']);

        $response = $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/concerns?search=Bob')
            ->assertOk();

        $this->assertSame(0, $response->json('meta.total'), 'the search named an anonymous reporter');
    }

    /**
     * The half that must still work: a concern filed openly is meant to be findable by who
     * filed it. Without this, the guard above could be satisfied by breaking the search.
     */
    public function test_searching_a_name_still_finds_their_named_concern(): void
    {
        $this->concern($this->bob, ['is_anonymous' => false]);
        $this->concern($this->alice, ['is_anonymous' => false]);

        $response = $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/concerns?search=Bob')
            ->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
    }

    /**
     * An anonymous concern whose reporter has since agreed to be named is STILL not matchable by
     * name. identityVisibleTo() grants that to hr_officer specifically, and a query has no
     * viewer — matching it would expose the reporter to a super_admin the resource would not name.
     */
    public function test_a_revealed_anonymous_concern_is_still_not_matchable_by_name(): void
    {
        $this->concern($this->bob, [
            'is_anonymous' => true,
            'identity_revealed_at' => now(),
        ]);

        $this->assertSame(
            0,
            $this->actingAs($this->hrUser())->getJson('/api/v1/hr/concerns?search=Bob')->json('meta.total'),
        );
    }

    /** The text of a concern is still searchable — anonymity is about WHO, not what. */
    public function test_the_concern_text_is_searchable_whether_anonymous_or_not(): void
    {
        $this->concern($this->bob, ['is_anonymous' => true, 'description' => 'Unsafe scaffolding on site']);

        $this->assertSame(
            1,
            $this->actingAs($this->hrUser())->getJson('/api/v1/hr/concerns?search=scaffolding')->json('meta.total'),
        );
    }

    // --- ordinary queue guarantees ------------------------------------------------------

    public function test_the_concern_queue_pages_and_leads_with_the_undecided(): void
    {
        // Seeded first and dated oldest: every fallback ordering puts it last.
        $waiting = $this->concern($this->alice, ['status' => 'pending', 'submitted_at' => now()->subYear()]);
        foreach (range(1, 30) as $i) {
            $this->concern($this->alice, ['status' => 'resolved']);
        }

        $response = $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/concerns?per_page=10')
            ->assertOk();

        $this->assertSame(31, $response->json('meta.total'));
        $response->assertJsonCount(10, 'data');
        $this->assertSame($waiting->id, $response->json('data.0.id'), 'the undecided concern must lead');
    }

    public function test_an_employee_only_sees_their_own_concerns(): void
    {
        $this->concern($this->alice);
        $this->concern($this->bob, ['description' => 'Bob private matter']);

        $response = $this->actingAs($this->alice->user)
            ->getJson('/api/v1/hr/me/concerns?search=private')
            ->assertOk();

        $this->assertSame(0, $response->json('meta.total'), 'the search reached outside the scope');
    }

    // --- the office-expense ledger -------------------------------------------------------

    public function test_the_expense_ledger_pages_and_searches_the_whole_thing(): void
    {
        $hrUser = $this->hrUser();
        OfficeExpense::create([
            'category' => 'equipment', 'amount' => 45000, 'expense_date' => '2020-01-01',
            'description' => 'Distinctive standing desk', 'vendor' => 'Acme',
            'status' => 'recorded', 'recorded_by' => $hrUser->id,
        ]);
        foreach (range(1, 30) as $i) {
            OfficeExpense::create([
                'category' => 'supplies', 'amount' => 100 + $i, 'expense_date' => "2026-06-{$i}",
                'description' => "Paper {$i}", 'vendor' => 'Officemart',
                'status' => 'approved', 'recorded_by' => $hrUser->id,
            ]);
        }

        $page1 = $this->actingAs($hrUser)->getJson('/api/v1/hr/office-expenses?per_page=10')->assertOk();
        $this->assertSame(31, $page1->json('meta.total'));

        // Oldest by date and lowest id, so only the priority rung can put it first.
        $this->assertSame('recorded', $page1->json('data.0.status'), 'an unreviewed expense must lead');

        $found = $this->actingAs($hrUser)
            ->getJson('/api/v1/hr/office-expenses?search=Distinctive')->assertOk();
        $this->assertSame(1, $found->json('meta.total'));

        $byVendor = $this->actingAs($hrUser)
            ->getJson('/api/v1/hr/office-expenses?search=Officemart')->assertOk();
        $this->assertSame(30, $byVendor->json('meta.total'));
    }

    /**
     * Item 4 — the sort control the ledger offers is the query's, not the browser's. Sorting a
     * page reorders ten rows; sorting the query decides WHICH ten.
     */
    public function test_the_sort_control_reorders_the_whole_ledger(): void
    {
        $hrUser = $this->hrUser();
        // The biggest spend is the OLDEST row, so it is nowhere near the default first page.
        OfficeExpense::create([
            'category' => 'equipment', 'amount' => 999999, 'expense_date' => '2020-01-01',
            'description' => 'Server rack', 'vendor' => 'Acme',
            'status' => 'approved', 'recorded_by' => $hrUser->id,
        ]);
        foreach (range(1, 30) as $i) {
            OfficeExpense::create([
                'category' => 'supplies', 'amount' => 100 + $i, 'expense_date' => "2026-06-{$i}",
                'description' => "Paper {$i}", 'vendor' => 'Officemart',
                'status' => 'approved', 'recorded_by' => $hrUser->id,
            ]);
        }

        $top = $this->actingAs($hrUser)
            ->getJson('/api/v1/hr/office-expenses?per_page=10&sort=amount_desc')
            ->assertOk();

        $this->assertSame('Server rack', $top->json('data.0.description'));

        $bottom = $this->actingAs($hrUser)
            ->getJson('/api/v1/hr/office-expenses?per_page=10&sort=amount_asc')
            ->assertOk();

        $this->assertSame('Paper 1', $bottom->json('data.0.description'));
    }

    /** `?sort=` is a closed list, so it cannot become a way to order by an unshown column. */
    public function test_an_unknown_sort_falls_back_to_the_default_ordering(): void
    {
        $hrUser = $this->hrUser();
        OfficeExpense::create([
            'category' => 'supplies', 'amount' => 10, 'expense_date' => '2020-01-01',
            'description' => 'Awaiting review', 'vendor' => 'Acme',
            'status' => 'recorded', 'recorded_by' => $hrUser->id,
        ]);
        OfficeExpense::create([
            'category' => 'supplies', 'amount' => 9999, 'expense_date' => '2026-06-01',
            'description' => 'Settled', 'vendor' => 'Acme',
            'status' => 'approved', 'recorded_by' => $hrUser->id,
        ]);

        $rows = $this->actingAs($hrUser)
            ->getJson('/api/v1/hr/office-expenses?sort=amount%3B+drop+table')
            ->assertOk()->json('data');

        // The default ordering: unreviewed first, whatever its date or amount.
        $this->assertSame('Awaiting review', $rows[0]['description']);
    }

    /**
     * Item 5 — the spend cards. Counted over the WINDOW, not over the ten rows on screen, and
     * over the same window as the table so the two cannot contradict each other.
     */
    public function test_the_spend_summary_covers_the_window_not_the_page(): void
    {
        $hrUser = $this->hrUser();
        foreach (range(1, 30) as $i) {
            OfficeExpense::create([
                'category' => 'supplies', 'amount' => 100, 'expense_date' => '2026-06-15',
                'description' => "Paper {$i}", 'vendor' => 'Officemart',
                'status' => 'approved', 'recorded_by' => $hrUser->id,
            ]);
        }
        OfficeExpense::create([
            'category' => 'rent', 'amount' => 50000, 'expense_date' => '2026-06-20',
            'description' => 'June rent', 'vendor' => 'Landlord',
            'status' => 'approved', 'recorded_by' => $hrUser->id,
        ]);
        // Outside the window — must be excluded from BOTH the table and the cards.
        OfficeExpense::create([
            'category' => 'events', 'amount' => 777, 'expense_date' => '2025-01-05',
            'description' => 'Old party', 'vendor' => 'Caterer',
            'status' => 'approved', 'recorded_by' => $hrUser->id,
        ]);

        $url = '?from=2026-06-01&to=2026-06-30';

        $summary = $this->actingAs($hrUser)
            ->getJson("/api/v1/hr/office-expenses/summary{$url}")->assertOk()->json('data');

        $this->assertEqualsWithDelta(53000.0, $summary['total'], 0.01, 'the window total');
        $this->assertSame('rent', array_key_first($summary['by_category']), 'biggest category first');
        $this->assertArrayNotHasKey('events', $summary['by_category'], 'a row outside the window leaked in');

        // And the table agrees with the cards.
        $table = $this->actingAs($hrUser)
            ->getJson("/api/v1/hr/office-expenses{$url}&per_page=10")->assertOk();
        $this->assertSame(31, $table->json('meta.total'));
    }

    // --- the requisition board ------------------------------------------------------------

    /** Requisitions track progress in `stage`, not `status` — the pills must filter that column. */
    public function test_the_requisition_board_filters_on_its_stage_column(): void
    {
        $dept = Department::first();
        foreach (range(1, 24) as $i) {
            Requisition::create([
                'title' => "Backend Engineer {$i}", 'pipeline' => 'standard',
                'employment_type' => 'full_time', 'department_id' => $dept->id,
                'openings' => 1, 'stage' => 'sourcing', 'opened_at' => "2026-06-01",
                'location' => 'Manila',
            ]);
        }
        Requisition::create([
            'title' => 'Distinctive Designer', 'pipeline' => 'standard',
            'employment_type' => 'full_time', 'department_id' => $dept->id,
            'openings' => 1, 'stage' => 'offer', 'opened_at' => '2026-06-02',
            'location' => 'Cebu',
        ]);

        $hr = $this->hrUser();

        $all = $this->actingAs($hr)->getJson('/api/v1/hr/requisitions?per_page=10')->assertOk();
        $this->assertSame(25, $all->json('meta.total'));
        $all->assertJsonCount(10, 'data');

        $offers = $this->actingAs($hr)->getJson('/api/v1/hr/requisitions?per_page=10&status=offer')->assertOk();
        $this->assertSame(1, $offers->json('meta.total'), 'the filter must reach the `stage` column');

        $found = $this->actingAs($hr)->getJson('/api/v1/hr/requisitions?search=Distinctive')->assertOk();
        $this->assertSame(1, $found->json('meta.total'));
    }
}
