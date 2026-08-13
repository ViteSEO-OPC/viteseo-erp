<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmploymentHistory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Hand-editing the employment-history timeline.
 *
 * The timeline is written automatically — opened at hire, moved by a job-title change, closed by a
 * separation — and that stays exactly as it was. This service is the escape hatch for the thing
 * automation cannot do: fix a mistake. A title typed wrong two promotions ago, a role someone
 * forgot to record, a duplicate left by a mis-click. Without it the only correction available was
 * the `correction` change type, which renames the CURRENT role and nothing else.
 *
 * Nothing downstream reads this table — it is a record for people, not an input to payroll — so
 * these edits are free in a way the compensation timeline's deliberately are not (see
 * SalaryHistoryService, where every row prices a payslip).
 *
 * The one invariant worth keeping is ONE OPEN ROLE. `recordRoleChange()` finds the role to close by
 * looking for the row with no end date; two of those and a promotion closes an arbitrary one of
 * them. So an edit that would leave a second role open is refused rather than quietly accepted.
 */
class EmploymentHistoryService
{
    /** A role still being held has no end date; every other status describes how it ended. */
    public const OPEN_STATUS = 'current';

    /** @var list<string> */
    public const CLOSED_STATUSES = ['promoted', 'transferred', 'lateral', 'role_change', 'separated'];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Employee $employee, array $data): EmploymentHistory
    {
        $open = ($data['end_date'] ?? null) === null;

        $this->assertDatesOrdered($data['start_date'], $data['end_date'] ?? null);

        if ($open) {
            $this->assertNoOtherOpenRole($employee->id, null);
        }

        return EmploymentHistory::create([
            'employee_id' => $employee->id,
            'title' => $data['title'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'] ?? null,
            'status' => $this->statusFor($open, $data['status'] ?? null),
            'reason' => $data['reason'] ?? null,
            'changed_by' => Auth::id(),
        ]);
    }

    /**
     * A partial payload: only the keys sent are written, so the dialog can save one field.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(EmploymentHistory $history, array $data): EmploymentHistory
    {
        $start = $data['start_date'] ?? $history->start_date?->toDateString();
        $end = array_key_exists('end_date', $data)
            ? $data['end_date']
            : $history->end_date?->toDateString();

        $this->assertDatesOrdered($start, $end);

        if ($end === null) {
            $this->assertNoOtherOpenRole($history->employee_id, $history->id);
        }

        $history->update([
            'title' => $data['title'] ?? $history->title,
            'start_date' => $start,
            'end_date' => $end,
            'status' => $this->statusFor($end === null, $data['status'] ?? $history->status),
            'reason' => array_key_exists('reason', $data) ? $data['reason'] : $history->reason,
            'changed_by' => Auth::id(),
        ]);

        return $history->fresh();
    }

    public function delete(EmploymentHistory $history): void
    {
        $history->delete();
    }

    /**
     * The status has to agree with the end date, because the UI reads one from the other: a row with
     * no end date renders as "Present", and calling that anything but `current` puts a "SEPARATED"
     * badge on a role the person is still in.
     */
    private function statusFor(bool $open, ?string $requested): string
    {
        if ($open) {
            return self::OPEN_STATUS;
        }

        return in_array($requested, self::CLOSED_STATUSES, true) ? $requested : 'role_change';
    }

    private function assertDatesOrdered(?string $start, ?string $end): void
    {
        if ($start === null || $end === null) {
            return;
        }

        if (Carbon::parse($end)->startOfDay()->lt(Carbon::parse($start)->startOfDay())) {
            throw ValidationException::withMessages([
                'end_date' => ['The role cannot end before it started.'],
            ]);
        }
    }

    /**
     * @param  int|null  $exceptId  the row being edited, which is allowed to be the open one
     */
    private function assertNoOtherOpenRole(int $employeeId, ?int $exceptId): void
    {
        $other = EmploymentHistory::where('employee_id', $employeeId)
            ->whereNull('end_date')
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->first();

        if ($other !== null) {
            throw ValidationException::withMessages([
                'end_date' => [
                    "“{$other->title}” is already open with no end date. Give this role an end date, "
                        . 'or close that one first — only one role can be the current one.',
                ],
            ]);
        }
    }
}
