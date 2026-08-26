<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Indexes for the columns the list screens FILTER and SORT on. Without these, moving those
// screens to server-side paging buys nothing: the database still reads every row to hand back
// twenty-five, because ORDER BY on an unindexed column is a filesort over the whole table.
//
// Each index below is justified by a query that exists today, not by anticipation:
//   hr_attendance.work_date      AttendanceService::allForDate() — `where work_date = ?`. The two
//                                existing indexes lead with employee_id and source, so neither
//                                can serve a date-only lookup.
//   hr_leave_requests.created_at LeaveService::listRequests() — `latest()` over every employee.
//   hr_reimbursements.created_at ReimbursementService::list() — the same shape.
//   hr_concerns.submitted_at     ConcernService::listForHr() — `latest('submitted_at')`.
//   hr_audit_logs.created_at     ReportService::audit() — `where created_at >= ?` + sort. The
//                                existing (user_id, created_at) leads with user_id, which a
//                                date-only window cannot use.
//
// Deliberately NOT added: an (employee_id, created_at) composite for the "my requests" lists.
// It measured as a micro-optimisation — one person's own history is small enough that sorting it
// costs nothing — and it is a ONE-WAY DOOR. MySQL drops the plain employee_id index once the
// composite makes it redundant, then adopts the composite to back the foreign key and refuses to
// drop it ("errno 1553: needed in a foreign key constraint"). The migration could not be rolled
// back. The single-column created_at index is what the HR-wide lists actually need, and it
// touches no foreign key.
//
// Deliberately NOT indexed yet: `status`. The list screens do not filter on it server-side
// until the pagination work lands, and the right composite depends on the final query shape.
// Guessing now would add write cost for an index the optimiser may not choose.
return new class extends Migration
{
    /** @var list<array{table: string, columns: list<string>, name: string}> */
    private array $indexes = [
        ['table' => 'hr_attendance', 'columns' => ['work_date'], 'name' => 'hr_attendance_work_date_index'],
        ['table' => 'hr_leave_requests', 'columns' => ['created_at'], 'name' => 'hr_leave_requests_created_at_index'],
        ['table' => 'hr_reimbursements', 'columns' => ['created_at'], 'name' => 'hr_reimbursements_created_at_index'],
        ['table' => 'hr_concerns', 'columns' => ['submitted_at'], 'name' => 'hr_concerns_submitted_at_index'],
        ['table' => 'hr_audit_logs', 'columns' => ['created_at'], 'name' => 'hr_audit_logs_created_at_index'],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $index) {
            if (! Schema::hasTable($index['table']) || $this->indexExists($index['table'], $index['name'])) {
                continue;
            }

            Schema::table($index['table'], function (Blueprint $table) use ($index) {
                $table->index($index['columns'], $index['name']);
            });
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $index) {
            if (! Schema::hasTable($index['table']) || ! $this->indexExists($index['table'], $index['name'])) {
                continue;
            }

            Schema::table($index['table'], function (Blueprint $table) use ($index) {
                $table->dropIndex($index['name']);
            });
        }
    }

    /**
     * Adding an index that already exists is a hard error, and these names are stable enough
     * that a partially-applied run (or a hand-added index on someone's box) is worth tolerating.
     */
    private function indexExists(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $index) => $index['name'] === $name);
    }
};
