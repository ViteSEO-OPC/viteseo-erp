<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Meeting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class MeetingService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * Schedule a meeting HR sets directly with an employee (e.g. from Attendance,
     * to discuss lateness/violations). No concern is involved — the invite is
     * routed by employee_id, so it lands in the employee's meeting list + bell.
     *
     * @param array<string, mixed> $data
     */
    public function scheduleForEmployee(Employee $employee, array $data, User $actor): Meeting
    {
        $meeting = Meeting::create([
            'concern_id' => null,
            'employee_id' => $employee->id,
            'scheduled_by' => $actor->id,
            'scheduled_at' => $data['scheduled_at'],
            'location' => $data['location'] ?? null,
            'agenda' => $data['agenda'] ?? null,
            'status' => 'scheduled',
        ]);

        // Not a concern — the employee's name is allowed here.
        $this->audit->log('scheduled', 'Attendance', "Meeting with {$employee->user?->name} about attendance", $meeting);

        return $meeting;
    }

    /**
     * The employee's meeting invites — matched either directly (named concerns,
     * meeting.employee_id = them) or through their concern (anonymous concerns,
     * concern.employee_id = them). Anonymity is preserved: HR never learns which
     * meeting maps to which anonymous person; only the submitter resolves it here.
     */
    public function myMeetings(Employee $employee): Collection
    {
        return Meeting::with('concern')
            ->where(function ($q) use ($employee) {
                $q->where('employee_id', $employee->id)
                    ->orWhereHas('concern', fn ($c) => $c->where('employee_id', $employee->id));
            })
            ->latest('scheduled_at')
            ->get();
    }

    /** Accept or decline — without revealing who responded for anonymous concerns. */
    public function respond(Meeting $meeting, Employee $employee, string $response): Meeting
    {
        if (! $this->owns($meeting, $employee)) {
            throw ValidationException::withMessages(['meeting' => ['This invite is not yours.']]);
        }
        if (! in_array($response, ['accepted', 'declined'], true)) {
            throw ValidationException::withMessages(['response' => ['Invalid response.']]);
        }

        $meeting->update([
            'status' => $response,
            'invite_response_at' => now(),
        ]);

        return $meeting->load('concern');
    }

    private function owns(Meeting $meeting, Employee $employee): bool
    {
        if ($meeting->employee_id === $employee->id) {
            return true;
        }

        $meeting->loadMissing('concern');

        return $meeting->concern?->employee_id === $employee->id;
    }
}
