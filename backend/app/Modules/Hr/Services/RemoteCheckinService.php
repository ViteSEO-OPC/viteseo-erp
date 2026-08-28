<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\RemoteCheckin;
use App\Modules\Hr\Services\Concerns\PagesQueues;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/** Off-site time-ins: the employee submits a selfie, HR looks at it and decides. */
class RemoteCheckinService
{
    use PagesQueues;

    /** Where selfies live on the public disk (served via /storage, like receipts). */
    private const PHOTO_DIR = 'hr-remote-checkins';

    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly AuditService $audit,
        private readonly AttendancePolicyService $policy,
    ) {}

    /**
     * File a new off-site time-in.
     *
     * @throws ValidationException when the employee already has one in flight, or has
     *                             already started the day some other way
     */
    public function submit(Employee $employee, UploadedFile $photo, ?float $lat, ?float $lng): RemoteCheckin
    {
        if ($employee->managementExemptOn(
            now(config('app.timezone_local'))->toDateString(),
            (bool) $this->policy->get()->management_auto_attendance,
        )) {
            throw ValidationException::withMessages([
                'photo' => ['Management employees are exempt from attendance.'],
            ]);
        }

        // One in flight at a time. Without this, a double-tap or an impatient resubmit
        // becomes two pending rows, and approving both would try to write the same day twice.
        if ($this->pendingFor($employee) !== null) {
            throw ValidationException::withMessages([
                'photo' => ['You already have a time in waiting for HR to review.'],
            ]);
        }

        // Already on the clock — approving this later would collide with that row, and the
        // employee would have waited for a review that could never succeed. Say so now.
        $open = $this->attendance->todayFor($employee);
        if ($open?->time_in !== null) {
            $startedToday = $open->work_date->toDateString()
                === now(config('app.timezone_local'))->toDateString();

            throw ValidationException::withMessages([
                'photo' => [
                    $startedToday
                        ? 'You have already clocked in today.'
                        : 'You still have an open shift from ' . $open->work_date->format('M j')
                            . ' — clock out first, or ask HR to correct it.',
                ],
            ]);
        }

        $checkin = RemoteCheckin::create([
            'employee_id' => $employee->id,
            'photo_path' => $photo->store(self::PHOTO_DIR, 'local'),
            'lat' => $lat,
            'lng' => $lng,
            'status' => RemoteCheckin::STATUS_PENDING,
        ])->load('employee.user');

        $this->audit->log(
            'submitted',
            'Attendance',
            'Off-site time in submitted for review',
            $checkin,
        );

        return $checkin;
    }

    /** The employee's in-flight submission, if any. */
    public function pendingFor(Employee $employee): ?RemoteCheckin
    {
        return RemoteCheckin::where('employee_id', $employee->id)
            ->where('status', RemoteCheckin::STATUS_PENDING)
            ->latest()
            ->first();
    }

    /**
     * What the employee's own clock panel needs: their newest submission, whatever its
     * state. A rejected row still matters — it is how they learn they must re-submit.
     */
    public function latestFor(Employee $employee): ?RemoteCheckin
    {
        return RemoteCheckin::with('reviewer')
            ->where('employee_id', $employee->id)
            ->latest()
            ->first();
    }

    /**
     * HR's queue. Defaults to pending — the only status that needs action — but the whole
     * history is reachable for looking back at what was decided.
     *
     * @return Collection<int, RemoteCheckin>
     */
    /**
     * One page of the remote check-in queue.
     *
     * OLDEST FIRST, and that is not the usual ordering — whoever has been waiting longest is
     * reviewed first, because every minute of that wait is a minute the employee is already
     * working and not yet clocked in. Passed as an explicit sort so the trait's newest-first
     * default does not quietly invert it.
     *
     * There is nothing to search: a check-in is a photo and a pair of coordinates. The only
     * filter that means anything here is the status, which the caller supplies.
     *
     * @param  array<string, mixed>  $filters  status, page, per_page
     */
    public function list(?string $status = RemoteCheckin::STATUS_PENDING, array $filters = []): LengthAwarePaginator
    {
        return $this->paginateQueue(
            RemoteCheckin::with(['employee.user', 'reviewer'])
                ->when($status !== null, fn ($q) => $q->where('status', $status)),
            $filters,
            searchEmployee: true,
            sort: [['created_at', 'asc']],
        );
    }

    /**
     * Approve, creating the attendance row stamped at SUBMISSION time.
     *
     * The employee is never charged for HR's review delay — someone who submitted at 8:58
     * and is approved at 11:00 clocked in at 8:58, and is on time.
     */
    public function approve(RemoteCheckin $checkin, User $reviewer): RemoteCheckin
    {
        $this->assertPending($checkin, 'approve');

        $attendance = $this->attendance->recordVerifiedClockIn(
            $checkin->employee,
            $checkin->created_at,
            Attendance::LOCATION_REMOTE_APPROVED,
        );

        $checkin->update([
            'status' => RemoteCheckin::STATUS_APPROVED,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        $name = $checkin->employee->user?->name ?? $checkin->employee->name;
        $this->audit->log(
            'approved',
            'Attendance',
            "Approved {$name}'s off-site time in of "
                . $attendance->time_in->timezone(config('app.timezone_local'))->format('M j, g:i A'),
            $checkin,
        );

        return $checkin->fresh(['employee.user', 'reviewer']);
    }

    /** Decline, with a reason the employee will read. */
    public function reject(RemoteCheckin $checkin, User $reviewer, string $note): RemoteCheckin
    {
        $this->assertPending($checkin, 'reject');

        $checkin->update([
            'status' => RemoteCheckin::STATUS_REJECTED,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'rejection_note' => $note,
        ]);

        $name = $checkin->employee->user?->name ?? $checkin->employee->name;
        $this->audit->log(
            'declined',
            'Attendance',
            "Declined {$name}'s off-site time in",
            $checkin,
        );

        return $checkin->fresh(['employee.user', 'reviewer']);
    }

    /**
     * A decided row is done. Re-deciding it would either duplicate an attendance row or
     * silently strand one that has already been written.
     */
    private function assertPending(RemoteCheckin $checkin, string $action): void
    {
        if ($checkin->status !== RemoteCheckin::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => ["This time in has already been {$checkin->status} — you can't {$action} it again."],
            ]);
        }
    }
}
