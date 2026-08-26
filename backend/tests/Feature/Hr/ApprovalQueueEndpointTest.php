<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\ChangeRequest;
use App\Modules\Hr\Models\Concern;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\Reimbursement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * The dashboard's approvals card, assembled on the SERVER.
 *
 * Thirteen queues feed that card. The browser used to ask all thirteen endpoints, merge the
 * answers and sort them itself — thirteen round trips and up to thirteen hundred rows travelling
 * to draw twenty. Sorting thirteen lists by date to take the oldest twenty is a database's job.
 *
 * Two things have to hold for that move to be safe, and they are what this file pins:
 *
 *   · the merge must be a real merge — the oldest row overall leads, whichever queue it came
 *     from, and a bounded read of each queue must not lose it;
 *   · an anonymous concern must never carry a name. That guard used to live in the browser,
 *     which meant the name was sent and then hidden. It is not sent now.
 */
class ApprovalQueueEndpointTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $alice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-08-20 09:00', config('app.timezone_local')));

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->alice = Employee::create([
            'user_id' => User::factory()->create(['name' => 'Alice Reyes'])->id,
            'department_id' => $dept->id, 'employee_no' => 'E-001', 'job_title' => 'Staff',
            'hire_date' => '2020-01-01', 'employment_status' => 'regular',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);
    }

    private function claim(string $createdAt, string $note = 'Taxi'): Reimbursement
    {
        $c = Reimbursement::create([
            'employee_id' => $this->alice->id, 'category' => 'transport', 'amount' => 500,
            'expense_date' => '2026-08-01', 'description' => $note, 'status' => 'pending',
        ]);
        Reimbursement::whereKey($c->id)->update(['created_at' => $createdAt]);

        return $c;
    }

    private function leave(string $createdAt): LeaveRequest
    {
        $type = LeaveType::firstOrCreate(
            ['name' => 'Vacation Leave'],
            ['code' => 'VL', 'days_per_year' => 15, 'is_paid' => true, 'requires_approval' => true],
        );
        $l = LeaveRequest::create([
            'employee_id' => $this->alice->id, 'leave_type_id' => $type->id,
            'start_date' => '2026-09-01', 'end_date' => '2026-09-01', 'days' => 1,
            'reason' => 'Trip', 'status' => 'pending',
        ]);
        LeaveRequest::whereKey($l->id)->update(['created_at' => $createdAt]);

        return $l;
    }

    private function queue(string $query = ''): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->hrUser())
            ->getJson("/api/v1/hr/approvals/pending{$query}")
            ->assertOk();
    }

    // --- the merge -----------------------------------------------------------------------

    /** Oldest first across every queue — this is a to-do list, so the longest wait leads. */
    public function test_the_oldest_outstanding_item_leads_whatever_queue_it_came_from(): void
    {
        $this->claim('2026-08-19 10:00:00');
        $oldest = $this->leave('2026-01-05 08:00:00'); // seven months older, different queue
        $this->claim('2026-08-18 10:00:00');

        $rows = $this->queue()->json('data');

        $this->assertSame('leave', $rows[0]['kind']);
        $this->assertSame($oldest->id, $rows[0]['id']);
    }

    /**
     * THE BOUNDED-READ GUARD. Each queue is read only as deep as the card can show. That is safe
     * only because a queue cannot contribute a row older than its own oldest — so the overall
     * oldest N can only come from each queue's own oldest N. A claim queue stuffed with recent
     * rows must not push an old leave request off the card.
     */
    public function test_a_busy_queue_cannot_crowd_out_an_older_item_from_another(): void
    {
        foreach (range(1, 40) as $i) {
            $this->claim(sprintf('2026-08-%02d 10:00:00', ($i % 28) + 1), "Claim {$i}");
        }
        $stale = $this->leave('2025-01-01 08:00:00'); // older than everything

        $rows = $this->queue('?limit=5')->json('data');

        $this->assertCount(5, $rows);
        $this->assertSame($stale->id, $rows[0]['id'], 'the longest-waiting item must survive the bound');
        $this->assertSame('leave', $rows[0]['kind']);
    }

    /** `total` counts everything outstanding, not what fitted on the card. */
    public function test_the_total_counts_every_outstanding_item(): void
    {
        foreach (range(1, 12) as $i) {
            $this->claim(sprintf('2026-08-%02d 10:00:00', $i));
        }
        $this->leave('2026-08-01 08:00:00');

        $response = $this->queue('?limit=5');

        $response->assertJsonCount(5, 'data');
        $this->assertSame(13, $response->json('meta.total'));
    }

    public function test_the_limit_is_capped(): void
    {
        $this->claim('2026-08-01 10:00:00');

        $this->queue('?limit=100000')->assertJsonPath('meta.limit', 100);
    }

    /** Two queues can both hold a row 7, so the key has to carry the kind. */
    public function test_rows_from_different_queues_do_not_collide(): void
    {
        $this->claim('2026-08-01 10:00:00');
        $this->leave('2026-08-02 10:00:00');

        $keys = collect($this->queue()->json('data'))->pluck('key')->all();

        $this->assertCount(count($keys), array_unique($keys), 'two queues produced the same key');
    }

    // --- the anonymity invariant ----------------------------------------------------------

    /**
     * An anonymous concern must not carry a name. This guard used to be a ternary in the browser
     * — which meant the reporter's name travelled to the client and was then chosen not to be
     * displayed. Anyone reading the network tab could see it. It is not sent at all now.
     */
    public function test_an_anonymous_concern_never_carries_a_name(): void
    {
        Concern::create([
            'employee_id' => $this->alice->id, 'reference_no' => 'CN-0001',
            'category' => 'workplace', 'description' => 'Something happened',
            'is_anonymous' => true, 'status' => 'pending', 'submitted_at' => now(),
        ]);

        $row = collect($this->queue()->json('data'))->firstWhere('kind', 'concern');

        $this->assertSame('Anonymous', $row['name']);
        $this->assertStringNotContainsString(
            'Alice',
            json_encode($this->queue()->json('data')),
            "the reporter's name must not reach the browser at all",
        );
    }

    /** A named concern is still named — the guard must not be satisfied by hiding everyone. */
    public function test_a_named_concern_still_carries_its_reporter(): void
    {
        Concern::create([
            'employee_id' => $this->alice->id, 'reference_no' => 'CN-0002',
            'category' => 'workplace', 'description' => 'Filed openly',
            'is_anonymous' => false, 'status' => 'pending', 'submitted_at' => now(),
        ]);

        $row = collect($this->queue()->json('data'))->firstWhere('kind', 'concern');

        $this->assertSame('Alice Reyes', $row['name']);
    }

    // --- the shape the card renders ---------------------------------------------------------

    public function test_each_row_carries_what_the_card_needs(): void
    {
        ChangeRequest::create([
            'employee_id' => $this->alice->id, 'field' => 'phone',
            'old_value' => '0900', 'new_value' => '0917', 'status' => 'pending',
        ]);

        $row = collect($this->queue()->json('data'))->firstWhere('kind', 'change');

        $this->assertSame(['key', 'kind', 'id', 'name', 'created_at', 'href'], array_keys($row));
        $this->assertSame('Alice Reyes', $row['name']);
        $this->assertSame('/teamora/requests/document', $row['href']);
    }

    /** Decided items are gone from the card — it is a to-do list, not a history. */
    public function test_a_decided_item_leaves_the_card(): void
    {
        $claim = $this->claim('2026-08-01 10:00:00');
        $this->assertSame(1, $this->queue()->json('meta.total'));

        $claim->update(['status' => 'approved']);

        $this->assertSame(0, $this->queue()->json('meta.total'));
    }
}
