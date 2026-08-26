<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The HR notification feed reads twelve approval queues — "everything still pending" per request
// type — and now asks each of them two questions: how many are outstanding (the bell and the
// sidebar badges) and which few belong on the page being shown. Both are `where status = ?`, and
// the page half then orders on the timestamp.
//
// `(status, timestamp)` serves both from one index: the COUNT stops at the matching range instead
// of scanning the table, and the page read walks that range already in order rather than sorting
// it. Status-first because status is the equality filter — an index leading with the timestamp
// could not narrow to pending at all.
//
// Not included:
//   hr_remote_checkins   already has exactly this index.
//   hr_employee_loans    has (employee_id, status), which leads with the wrong column for a
//                        status-only count, so it gets its own.
//
// These lead with `status`, not a foreign key, so none of them can end up backing an FK the way
// an (employee_id, ...) composite would — see the note in the previous index migration.
return new class extends Migration
{
    /** @var list<array{table: string, columns: list<string>, name: string}> */
    private array $indexes = [
        ['table' => 'hr_leave_requests', 'columns' => ['status', 'created_at'], 'name' => 'hr_leave_requests_status_created_at_index'],
        ['table' => 'hr_half_day_requests', 'columns' => ['status', 'created_at'], 'name' => 'hr_half_day_requests_status_created_at_index'],
        ['table' => 'hr_early_out_requests', 'columns' => ['status', 'created_at'], 'name' => 'hr_early_out_requests_status_created_at_index'],
        ['table' => 'hr_overtime_requests', 'columns' => ['status', 'created_at'], 'name' => 'hr_overtime_requests_status_created_at_index'],
        ['table' => 'hr_employee_loans', 'columns' => ['status', 'created_at'], 'name' => 'hr_employee_loans_status_created_at_index'],
        ['table' => 'hr_reimbursements', 'columns' => ['status', 'created_at'], 'name' => 'hr_reimbursements_status_created_at_index'],
        ['table' => 'hr_document_requests', 'columns' => ['status', 'created_at'], 'name' => 'hr_document_requests_status_created_at_index'],
        ['table' => 'hr_change_requests', 'columns' => ['status', 'created_at'], 'name' => 'hr_change_requests_status_created_at_index'],
        ['table' => 'hr_payslip_requests', 'columns' => ['status', 'created_at'], 'name' => 'hr_payslip_requests_status_created_at_index'],
        ['table' => 'hr_payroll_inquiries', 'columns' => ['status', 'created_at'], 'name' => 'hr_payroll_inquiries_status_created_at_index'],
        // The concerns queue is ordered by when it was SUBMITTED, not when the row was written.
        ['table' => 'hr_concerns', 'columns' => ['status', 'submitted_at'], 'name' => 'hr_concerns_status_submitted_at_index'],
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

    /** Tolerates a partially-applied run, or an index someone added by hand. */
    private function indexExists(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $index) => $index['name'] === $name);
    }
};
