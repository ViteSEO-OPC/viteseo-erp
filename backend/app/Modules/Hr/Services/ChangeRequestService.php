<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\ChangeRequest;
use App\Modules\Hr\Models\Employee;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChangeRequestService
{
    /**
     * Employee fields an employee may request a change to, with labels. All personal +
     * bank columns on hr_employees. (Email/login identity lives on the users row and is
     * NOT self-service — it goes through HR directly.)
     */
    public const FIELDS = [
        'first_name' => 'First name',
        'middle_name' => 'Middle name',
        'last_name' => 'Last name',
        'name_extension' => 'Name extension',
        'phone' => 'Contact number',
        'gender' => 'Gender',
        'civil_status' => 'Civil status',
        'nationality' => 'Nationality',
        'date_of_birth' => 'Date of birth',
        'place_of_birth' => 'Place of birth',
        'address' => 'Home address',
        'current_address' => 'Current address',
        'mothers_maiden_name' => "Mother's maiden name",
        'fathers_name' => "Father's name",
        'siblings_name' => 'Siblings name',
        'high_school' => 'High school',
        'college_course' => 'College course',
        'university' => 'University/College',
        'emergency_contact_name' => 'Emergency contact name',
        'emergency_contact_relationship' => 'Emergency contact relationship',
        'emergency_contact_phone' => 'Emergency contact number',
        'height' => 'Height',
        'weight' => 'Weight',
        'health_condition' => 'Health concern/condition',
        'bank_name' => 'Bank name',
        'bank_account' => 'Bank account',
        'bank_account_type' => 'Bank account type',
        'bank_account_name' => 'Bank account name',
    ];

    public function __construct(private readonly AuditService $audit) {}

    public function list(User $user): Collection
    {
        $query = ChangeRequest::with(['employee.user', 'reviewer'])->latest();

        if (! $user->hasAnyRole(['hr_officer', 'super_admin'])) {
            $query->where('employee_id', $user->employee?->id);
        }

        return $query->get();
    }

    /**
     * The employee proposes a new value; we snapshot the current value as
     * old_value so HR sees the before/after.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(Employee $employee, array $data): ChangeRequest
    {
        $field = $data['field'];

        $request = ChangeRequest::create([
            'employee_id' => $employee->id,
            'field' => $field,
            'old_value' => $employee->{$field},
            'new_value' => $data['new_value'],
            'status' => 'pending',
        ])->load('employee.user');

        $label = self::FIELDS[$field] ?? $field;
        $this->audit->log(
            'submitted',
            'Profile',
            "{$label} change request",
            $request,
        );

        return $request;
    }

    /**
     * The employee proposes several field changes at once — each becomes its OWN
     * pending request so HR can approve/reject them individually.
     *
     * @param  array<int, array{field:string, new_value:string}>  $changes
     * @return array<int, ChangeRequest>
     */
    public function createMany(Employee $employee, array $changes): array
    {
        return array_map(fn ($change) => $this->create($employee, $change), $changes);
    }

    /** Approve = write new_value onto the employee record + audit-log before/after. */
    public function approve(ChangeRequest $request, User $reviewer): ChangeRequest
    {
        $this->assertPending($request, 'approve');

        return DB::transaction(function () use ($request, $reviewer) {
            $employee = $request->employee;
            $field = $request->field;
            $before = [$field => $employee->{$field}];

            $employee->update([$field => $request->new_value]);

            $request->update([
                'status' => 'approved',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ]);

            $label = self::FIELDS[$field] ?? $field;
            $employeeName = $employee->user?->name ?? $employee->name;
            $this->audit->log(
                'approved',
                'Profile',
                "Approved {$label} change for {$employeeName}",
                $employee,
                $before,
                [$field => $request->new_value],
            );

            return $request->load(['employee.user', 'reviewer']);
        });
    }

    public function reject(ChangeRequest $request, User $reviewer, ?string $reason = null): ChangeRequest
    {
        $this->assertPending($request, 'reject');

        $request->update([
            'status' => 'rejected',
            'review_note' => $reason,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        $label = self::FIELDS[$request->field] ?? $request->field;
        $employee = $request->employee;
        $employeeName = $employee->user?->name ?? $employee->name;
        $this->audit->log(
            'declined',
            'Profile',
            "Declined {$label} change for {$employeeName}",
            $request,
        );

        return $request->load(['employee.user', 'reviewer']);
    }

    private function assertPending(ChangeRequest $request, string $action): void
    {
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => ["Cannot {$action} a request that is {$request->status}."],
            ]);
        }
    }
}
