<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\Concern;
use App\Modules\Hr\Services\Concerns\PagesQueues;
use App\Modules\Hr\Services\Concerns\CancelsOwnRequest;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Meeting;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ConcernService
{
    use PagesQueues;

    use CancelsOwnRequest;

    public const CATEGORIES = ['conduct', 'harassment', 'safety', 'policy', 'other'];

    public function __construct(private readonly AuditService $audit) {}

    /** HR/Admin queue — all concerns, newest first. */
    /**
     * One page of the concern queue, for HR.
     *
     * The search deliberately does NOT match the reporter's name in general — see
     * concernSearch(). Everything else is the ordinary queue treatment.
     *
     * @param  array<string, mixed>  $filters  search, status, page, per_page
     */
    public function listForHr(array $filters = []): LengthAwarePaginator
    {
        $query = Concern::with(['employee.user', 'meetings', 'canceller', 'revealRequests.requester']);

        return $this->paginateQueue(
            $query,
            $filters,
            ['reference_no', 'category', 'description', 'reported_person'],
            searchEmployee: false,
            priorityStatuses: ['pending'],
            recencyColumn: 'submitted_at',
            extraSearch: $this->concernSearch(),
        );
    }

    /**
     * Why the reporter's name is not an ordinary search column here.
     *
     * ConcernResource already hides who filed an anonymous concern. A search that matched the
     * name would hand that back anyway, through the result rather than the payload: type a
     * colleague's name into the box, and whichever anonymous concerns come back are theirs. The
     * identity is inferred from WHICH ROWS MATCH, and nothing in the response has to name them
     * for that to work.
     *
     * So the name is matchable only on concerns that are not anonymous at all. Deliberately not
     * "anonymous but since revealed" either: identityVisibleTo() grants that to hr_officer
     * specifically, and a query has no viewer — matching it would expose the reporter to a
     * super_admin the resource itself would not name.
     */
    private function concernSearch(): \Closure
    {
        return function ($group, string $search) {
            $group->orWhere(
                fn ($q) => $q->where('is_anonymous', false)
                    ->whereHas('employee', fn ($e) => $e->whereHas(
                        'user',
                        fn ($u) => $u->where('name', 'like', "%{$search}%"),
                    )),
            );
        };
    }

    /** The employee's own concerns (named or anonymous). */
    /**
     * One page of the caller's own concerns. Scoped before the filters, so no search widens it
     * — and the reporter is themselves, so the anonymity rule above has nothing to protect here.
     *
     * @param  array<string, mixed>  $filters  search, status, page, per_page
     */
    public function listForEmployee(Employee $employee, array $filters = []): LengthAwarePaginator
    {
        $query = Concern::with(['meetings', 'canceller', 'revealRequests.requester'])
            ->where('employee_id', $employee->id);

        return $this->paginateQueue(
            $query,
            $filters,
            ['reference_no', 'category', 'description', 'reported_person'],
            searchEmployee: false,
            priorityStatuses: ['pending'],
            recencyColumn: 'submitted_at',
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>  $files
     */
    public function create(Employee $employee, array $data, array $files = []): Concern
    {
        $isAnonymous = (bool) ($data['is_anonymous'] ?? false);

        $concern = Concern::create([
            'employee_id' => $employee->id, // stored, but hidden for anonymous (Resource)
            'reference_no' => $this->uniqueReference(),
            'category' => $data['category'],
            'description' => $data['description'],
            'reported_person' => $data['reported_person'] ?? null,
            'attachments' => $this->storeAttachments($files, $isAnonymous),
            'is_anonymous' => $isAnonymous,
            'status' => 'pending',
            'submitted_at' => now(),
        ]);

        // ANONYMITY: identify the concern by category + reference only — never the submitter.
        $this->audit->log('submitted', 'Concerns', "{$concern->category} concern ({$concern->reference_no})", $concern);

        return $concern;
    }

    /**
     * Persist uploaded documents and return their JSON metadata. For ANONYMOUS
     * concerns the submitter's identity is protected before storage: images are
     * re-encoded to strip EXIF/metadata, and the display name is generalised so an
     * original filename (which can carry a person's name) is never surfaced. Files
     * always land under a random hashed path, so the stored path is never guessable.
     *
     * @param  array<int, UploadedFile>  $files
     * @return array<int, array<string, mixed>>
     */
    private function storeAttachments(array $files, bool $anonymous): array
    {
        $out = [];

        foreach (array_values($files) as $i => $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $ext = strtolower($file->getClientOriginalExtension());
            $path = $this->storeFile($file, $ext, $anonymous);

            $out[] = [
                'path' => $path,
                'name' => $anonymous
                    ? 'Attachment ' . ($i + 1) . ($ext ? ".{$ext}" : '')
                    : $file->getClientOriginalName(),
                'size' => Storage::disk('local')->size($path),
                'mime' => $file->getClientMimeType(),
            ];
        }

        return $out;
    }

    private function storeFile(UploadedFile $file, string $ext, bool $anonymous): string
    {
        // Strip metadata from images on anonymous submissions by re-encoding via GD;
        // this drops EXIF (camera, GPS, author) that could deanonymise the submitter.
        if ($anonymous && in_array($ext, ['jpg', 'jpeg', 'png'], true) && function_exists('imagecreatefromstring')) {
            $src = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

            if ($src !== false) {
                ob_start();
                $ext === 'png' ? imagepng($src) : imagejpeg($src, null, 90);
                $bytes = (string) ob_get_clean();
                imagedestroy($src);

                $path = 'concern-attachments/' . Str::random(40) . ".{$ext}";
                Storage::disk('local')->put($path, $bytes);

                return $path;
            }
        }

        return $file->store('concern-attachments', 'local');
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

        return $concern->load(['employee.user', 'meetings', 'revealRequests.requester']);
    }

    /**
     * Undo the last mark: resolved → under review, under review → pending. Any meeting
     * already scheduled off this concern stays — reopening the case does not unbook it.
     */
    public function reopen(Concern $concern, ?string $reason = null): Concern
    {
        if ($concern->status === 'pending') {
            throw ValidationException::withMessages([
                'status' => ['This concern is still pending — there is nothing to undo.'],
            ]);
        }

        $next = $concern->status === 'resolved' ? 'under_review' : 'pending';

        $concern->update(['status' => $next, 'resolved_at' => null]);

        // ANONYMITY: reference only — never the submitter's identity.
        $this->audit->log(
            'reopened',
            'Concerns',
            "Concern {$concern->reference_no} reopened as {$next}" . ($reason ? " — {$reason}" : ''),
            $concern,
        );

        return $concern->load(['employee.user', 'meetings', 'revealRequests.requester']);
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

    /** The employee withdraws a concern, while HR has yet to open it. */
    public function cancel(Concern $concern, User $actor, string $reason): Concern
    {
        $this->applyCancellation($concern, $actor, $reason, 'concern');

        // Reference number only — the description may name people, and audit lines are read by
        // more eyes than the concern itself.
        $this->audit->log(
            'cancelled',
            'Concerns',
            "Concern {$concern->reference_no} withdrawn by the person who raised it",
            $concern,
        );

        return $concern->load(['employee.user', 'canceller', 'revealRequests.requester']);
    }

    private function uniqueReference(): string
    {
        do {
            $ref = 'CN-' . strtoupper(Str::random(6));
        } while (Concern::where('reference_no', $ref)->exists());

        return $ref;
    }
}
