<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\DocumentRequest;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmployeeDocument;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DocumentRequestService
{
    public const TYPES = ['payroll', 'leave', 'attendance', 'coe', 'other'];

    public function __construct(private readonly AuditService $audit) {}

    /** HR sees all; an employee sees only their own. */
    public function list(User $user): Collection
    {
        $query = DocumentRequest::with(['employee.user', 'reviewer'])->latest();

        if (! $user->hasAnyRole(['hr_officer', 'super_admin'])) {
            $query->where('employee_id', $user->employee?->id);
        }

        return $query->get();
    }

    /** @param array<string, mixed> $data */
    public function create(Employee $employee, array $data): DocumentRequest
    {
        $documentId = $data['employee_document_id'] ?? null;
        $type = $data['type'] ?? 'record';
        $otherDetail = $data['other_detail'] ?? null;

        // A request for a specific uploaded document: derive type + label from it so
        // HR sees which document is being asked for.
        if ($documentId) {
            $type = 'record';
            $otherDetail = EmployeeDocument::find($documentId)?->name;
        }

        $request = DocumentRequest::create([
            'employee_id' => $employee->id,
            'employee_document_id' => $documentId,
            'type' => $type,
            'other_detail' => $otherDetail,
            'reason' => $data['reason'] ?? null,
            'status' => 'pending',
        ])->load('employee.user');

        $this->audit->log(
            'submitted',
            'Documents',
            "{$request->type} record request",
            $request,
        );

        return $request;
    }

    /**
     * The employee's HR-uploaded documents with per-document access state: they see
     * every document listed, but the download URL is only exposed once a request for
     * it has been fulfilled by HR.
     *
     * @return array<int, array<string, mixed>>
     */
    public function myDocumentsWithAccess(Employee $employee): array
    {
        $rank = ['rejected' => 1, 'pending' => 2, 'in_progress' => 3, 'fulfilled' => 4];

        // Best request status per document id.
        $statuses = [];
        DocumentRequest::where('employee_id', $employee->id)
            ->whereNotNull('employee_document_id')
            ->get(['employee_document_id', 'status'])
            ->each(function ($r) use (&$statuses, $rank) {
                $cur = $statuses[$r->employee_document_id] ?? null;
                if ($cur === null || ($rank[$r->status] ?? 0) > ($rank[$cur] ?? 0)) {
                    $statuses[$r->employee_document_id] = $r->status;
                }
            });

        return $employee->documents()->latest()->get()->map(function ($doc) use ($statuses) {
            $status = $statuses[$doc->id] ?? 'none';
            $unlocked = $status === 'fulfilled';

            return [
                'id' => $doc->id,
                'name' => $doc->name,
                'label' => $doc->label,
                'size_bytes' => $doc->size_bytes,
                'created_at' => $doc->created_at?->format('Y-m-d'),
                'request_status' => $status,
                'unlocked' => $unlocked,
                'url' => $unlocked && $doc->file_path ? Storage::disk('public')->url($doc->file_path) : null,
            ];
        })->all();
    }

    public function fulfill(DocumentRequest $request, User $reviewer, ?UploadedFile $file = null, ?string $note = null): DocumentRequest
    {
        $this->assertOpen($request, 'fulfil');

        $request->update([
            'status' => 'fulfilled',
            'fulfilled_path' => $file ? $file->store('fulfilled-documents', 'public') : $request->fulfilled_path,
            'review_note' => $note,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        $this->audit->log(
            'approved',
            'Documents',
            "Fulfilled {$request->type} record request",
            $request,
        );

        return $request->load(['employee.user', 'reviewer']);
    }

    public function reject(DocumentRequest $request, User $reviewer, ?string $reason = null): DocumentRequest
    {
        $this->assertOpen($request, 'reject');

        $request->update([
            'status' => 'rejected',
            'review_note' => $reason,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        $this->audit->log(
            'declined',
            'Documents',
            "Declined {$request->type} record request",
            $request,
        );

        return $request->load(['employee.user', 'reviewer']);
    }

    private function assertOpen(DocumentRequest $request, string $action): void
    {
        if (! in_array($request->status, ['pending', 'in_progress'], true)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot {$action} a request that is {$request->status}."],
            ]);
        }
    }
}
