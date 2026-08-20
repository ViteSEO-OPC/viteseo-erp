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
     * NOT self-service — it goes through HR directly. Siblings are excluded too: they
     * are a structured list, which a single-string request cannot express.)
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

        // The requestable list can shrink (a field may become structured data). Refuse
        // an in-flight request for such a field rather than writing a column blindly.
        if (! array_key_exists($request->field, self::FIELDS)) {
            throw ValidationException::withMessages([
                'field' => ['This field is no longer editable through self-service — change it on the employee record instead.'],
            ]);
        }

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

    /**
     * Undo the decision — back to pending. Approving wrote `new_value` onto the employee,
     * so undoing puts `old_value` back — but ONLY if the field still holds exactly what
     * this request set. HR can edit the same field directly on the record, and silently
     * reverting a newer, deliberate edit to a stale value would be worse than leaving it:
     * in that case the request reopens and the record is left as HR last set it.
     *
     * @return array{request: ChangeRequest, restored: bool}
     */
    public function reopen(ChangeRequest $request, ?string $reason = null): array
    {
        if ($request->status === 'pending') {
            throw ValidationException::withMessages([
                'status' => ['This request is still pending — there is nothing to undo.'],
            ]);
        }

        $wasApproved = $request->status === 'approved';
        $field = $request->field;
        $employee = $request->employee;

        // Only touch the record when the approval is still the last word on that field.
        $restorable = $wasApproved
            && array_key_exists($field, self::FIELDS)
            && (string) $employee->{$field} === (string) $request->new_value;

        $result = DB::transaction(function () use ($request, $employee, $field, $restorable, $reason) {
            $before = [$field => $employee->{$field}];

            if ($restorable) {
                $employee->update([$field => $request->old_value]);
            }

            $request->update([
                'status' => 'pending',
                'review_note' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
            ]);

            $label = self::FIELDS[$field] ?? $field;
            $employeeName = $employee->user?->name ?? $employee->name;
            $this->audit->log(
                'reopened',
                'Profile',
                "Reopened {$label} change for {$employeeName}"
                    . ($restorable ? ' and restored the previous value' : '')
                    . ($reason ? " — {$reason}" : ''),
                $employee,
                $restorable ? $before : null,
                $restorable ? [$field => $request->old_value] : null,
            );

            return $request->load(['employee.user', 'reviewer']);
        });

        return ['request' => $result, 'restored' => $restorable];
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
