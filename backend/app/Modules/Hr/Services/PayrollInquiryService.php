<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollInquiry;
use App\Modules\Hr\Services\Concerns\CancelsOwnRequest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class PayrollInquiryService
{
    use CancelsOwnRequest;

    public function __construct(private readonly AuditService $audit) {}

    public function list(User $user): Collection
    {
        $query = PayrollInquiry::with(['employee.user', 'resolver', 'payslip.payrollRun', 'canceller'])->latest();

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
            'attachment_path' => $attachment?->store('payroll-inquiries', 'local'),
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

    /**
     * Resolve an inquiry. HR must say how it was resolved (`$note`) and may attach
     * proof files. Each file is stored on the public disk; we keep its path (to build
     * a URL) and original name (to display).
     *
     * @param  array<int, UploadedFile>  $files
     */
    public function resolve(PayrollInquiry $inquiry, User $resolver, string $note, array $files = []): PayrollInquiry
    {
        $this->assertStatus($inquiry, ['pending', 'in_progress'], 'resolve');

        $attachments = [];
        foreach ($files as $file) {
            $attachments[] = [
                'path' => $file->store('payroll-inquiry-resolutions', 'local'),
                'name' => $file->getClientOriginalName(),
            ];
        }

        $inquiry->update([
            'status' => 'done',
            'resolution_note' => $note,
            'resolution_attachments' => $attachments ?: null,
            'resolved_by' => $resolver->id,
            'resolved_at' => now(),
        ]);

        $this->audit->log('resolved', 'Payroll', 'Resolved a payroll inquiry', $inquiry);

        return $inquiry->load(['employee.user', 'resolver', 'payslip.payrollRun']);
    }

    /**
     * Undo the last mark: done → in progress, in progress → pending. Resolving is
     * reversible because nothing outside the inquiry depends on it — the resolution
     * note and its attachments are dropped so HR writes a fresh answer.
     */
    public function reopen(PayrollInquiry $inquiry, ?string $reason = null): PayrollInquiry
    {
        $this->assertStatus($inquiry, ['in_progress', 'done'], 'reopen');

        $wasDone = $inquiry->status === 'done';

        $inquiry->update($wasDone ? [
            'status' => 'in_progress',
            'resolution_note' => null,
            'resolution_attachments' => null,
            'resolved_by' => null,
            'resolved_at' => null,
        ] : ['status' => 'pending']);

        $this->audit->log(
            'reopened',
            'Payroll',
            'Reopened a payroll inquiry' . ($reason ? " — {$reason}" : ''),
            $inquiry,
        );

        return $inquiry->load(['employee.user', 'resolver', 'payslip.payrollRun']);
    }

    /** @param array<int, string> $allowed */
    /** The employee withdraws an inquiry, while HR has yet to pick it up. */
    public function cancel(PayrollInquiry $inquiry, User $actor, string $reason): PayrollInquiry
    {
        $this->applyCancellation($inquiry, $actor, $reason, 'inquiry');

        $this->audit->log(
            'cancelled',
            'Payroll',
            "Cancelled a payroll inquiry — {$reason}",
            $inquiry,
        );

        return $inquiry->load(['employee.user', 'resolver', 'canceller']);
    }

    private function assertStatus(PayrollInquiry $inquiry, array $allowed, string $action): void
    {
        if (! in_array($inquiry->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot {$action} an inquiry that is {$inquiry->status}."],
            ]);
        }
    }
}
