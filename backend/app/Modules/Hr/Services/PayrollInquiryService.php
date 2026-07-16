<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollInquiry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class PayrollInquiryService
{
    public function __construct(private readonly AuditService $audit) {}

    public function list(User $user): Collection
    {
        $query = PayrollInquiry::with(['employee.user', 'resolver', 'payslip.payrollRun'])->latest();

        if (! $user->hasAnyRole(['hr_officer', 'super_admin'])) {
            $query->where('employee_id', $user->employee?->id);
        }

        return $query->get();
    }

    /** @param array<string, mixed> $data */
    public function create(Employee $employee, array $data, ?UploadedFile $attachment = null): PayrollInquiry
    {
        $inquiry = PayrollInquiry::create([
            'employee_id' => $employee->id,
            'payslip_id' => $data['payslip_id'] ?? null,
            'subject' => $data['subject'],
            'topic' => $data['topic'],
            'message' => $data['message'],
            // Stored on the public disk so HR can open it from the inquiry.
            'attachment_path' => $attachment?->store('payroll-inquiries', 'public'),
            'priority' => 'high',
            'status' => 'pending',
        ])->load('employee.user');

        $this->audit->log('submitted', 'Payroll', 'Payroll inquiry' . ($inquiry->subject ? ": {$inquiry->subject}" : ''), $inquiry);

        return $inquiry;
    }

    public function progress(PayrollInquiry $inquiry): PayrollInquiry
    {
        $this->assertStatus($inquiry, ['pending'], 'start');

        $inquiry->update(['status' => 'in_progress']);

        $this->audit->log('updated', 'Payroll', 'Marked payroll inquiry in progress', $inquiry);

        return $inquiry->load(['employee.user', 'payslip.payrollRun']);
    }

    public function resolve(PayrollInquiry $inquiry, User $resolver, ?string $note = null): PayrollInquiry
    {
        $this->assertStatus($inquiry, ['pending', 'in_progress'], 'resolve');

        $inquiry->update([
            'status' => 'done',
            'resolution_note' => $note,
            'resolved_by' => $resolver->id,
            'resolved_at' => now(),
        ]);

        $this->audit->log('resolved', 'Payroll', 'Resolved a payroll inquiry', $inquiry);

        return $inquiry->load(['employee.user', 'resolver', 'payslip.payrollRun']);
    }

    /** @param array<int, string> $allowed */
    private function assertStatus(PayrollInquiry $inquiry, array $allowed, string $action): void
    {
        if (! in_array($inquiry->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot {$action} an inquiry that is {$inquiry->status}."],
            ]);
        }
    }
}
