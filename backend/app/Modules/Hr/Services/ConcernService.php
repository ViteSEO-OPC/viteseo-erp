<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\Concern;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Meeting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ConcernService
{
    public const CATEGORIES = ['conduct', 'harassment', 'safety', 'policy', 'other'];

    public function __construct(private readonly AuditService $audit) {}

    /** HR/Admin queue — all concerns, newest first. */
    public function listForHr(): Collection
    {
        return Concern::with(['employee.user', 'meetings'])->latest('submitted_at')->get();
    }

    /** The employee's own concerns (named or anonymous). */
    public function listForEmployee(Employee $employee): Collection
    {
        return Concern::with('meetings')
            ->where('employee_id', $employee->id)
            ->latest('submitted_at')
            ->get();
    }

    /** @param array<string, mixed> $data */
    public function create(Employee $employee, array $data): Concern
    {
        $concern = Concern::create([
            'employee_id' => $employee->id, // stored, but hidden for anonymous (Resource)
            'reference_no' => $this->uniqueReference(),
            'category' => $data['category'],
            'description' => $data['description'],
            'is_anonymous' => (bool) ($data['is_anonymous'] ?? false),
            'status' => 'pending',
            'submitted_at' => now(),
        ]);

        // ANONYMITY: identify the concern by category + reference only — never the submitter.
        $this->audit->log('submitted', 'Concerns', "{$concern->category} concern ({$concern->reference_no})", $concern);

        return $concern;
    }

    public function setStatus(Concern $concern, string $status, User $actor): Concern
    {
        if (! in_array($status, ['under_review', 'resolved'], true)) {
            throw ValidationException::withMessages(['status' => ['Invalid status.']]);
        }

        $concern->update([
            'status' => $status,
            'resolved_at' => $status === 'resolved' ? now() : $concern->resolved_at,
        ]);

        // ANONYMITY: reference only — never the submitter's identity.
        $this->audit->log(
            $status === 'resolved' ? 'resolved' : 'updated',
            'Concerns',
            "Concern {$concern->reference_no} marked {$status}",
            $concern,
        );

        return $concern->load(['employee.user', 'meetings']);
    }

    /**
     * Schedule a meeting from a concern. A NAMED concern's meeting carries the
     * employee_id; an ANONYMOUS concern's meeting leaves it NULL and is routed to
     * the submitter through the concern link only.
     *
     * @param array<string, mixed> $data
     */
    public function scheduleMeeting(Concern $concern, array $data, User $actor): Meeting
    {
        $meeting = Meeting::create([
            'concern_id' => $concern->id,
            'employee_id' => $concern->is_anonymous ? null : $concern->employee_id,
            'scheduled_by' => $actor->id,
            'scheduled_at' => $data['scheduled_at'],
            'location' => $data['location'] ?? null,
            'agenda' => $data['agenda'] ?? null,
            'status' => 'scheduled',
        ]);

        // ANONYMITY: reference only — never the person the meeting is with.
        $this->audit->log('scheduled', 'Concerns', "Meeting scheduled re: {$concern->reference_no}", $concern);

        return $meeting->load('concern');
    }

    private function uniqueReference(): string
    {
        do {
            $ref = 'CN-' . strtoupper(Str::random(6));
        } while (Concern::where('reference_no', $ref)->exists());

        return $ref;
    }
}
