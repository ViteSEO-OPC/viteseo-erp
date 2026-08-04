<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Mail\CandidateNotificationMail;
use App\Modules\Hr\Models\Candidate;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Requisition;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecruitmentService
{
    public const SOURCES = ['referral', 'linkedin', 'jobboard', 'direct', 'agency'];
    // draft = not open yet · active = open (add/hire allowed) · closed = filled/done.
    public const REQ_STAGES = ['draft', 'active', 'closed'];

    public const PIPELINES = ['standard', 'ojt'];

    public const EMPLOYMENT_TYPES = ['full_time', 'part_time', 'contract', 'ojt', 'temporary'];

    /** Employment type decides the candidate pipeline (OJT gets the OJT flow). */
    public static function pipelineForEmployment(?string $employmentType): string
    {
        return $employmentType === 'ojt' ? 'ojt' : 'standard';
    }

    /**
     * The candidate stage flow per pipeline (config-driven, not a DB enum, so
     * naming can change without a migration). `hired`/`rejected` terminate both.
     * Stage naming is provisional pending confirmation with Miss Chris.
     */
    public const STAGE_FLOWS = [
        'standard' => [
            ['key' => 'screening', 'label' => 'Resume Screening'],
            ['key' => 'online_interview', 'label' => 'Initial Online Interview'],
            ['key' => 'final_interview', 'label' => 'Final / Technical Interview'],
            ['key' => 'assessment', 'label' => 'Assessment'],
            ['key' => 'hired', 'label' => 'Hired'],
            ['key' => 'rejected', 'label' => 'Rejected'],
        ],
        'ojt' => [
            ['key' => 'online_interview', 'label' => 'Online Interview'],
            ['key' => 'moa_review', 'label' => 'MOA Review'],
            ['key' => 'role_assignment', 'label' => 'Assignment of Roles'],
            ['key' => 'hired', 'label' => 'Hired'],
            ['key' => 'rejected', 'label' => 'Rejected'],
        ],
    ];

    /** Stages that expose the "send interview email" action. */
    public const INTERVIEW_STAGES = ['online_interview', 'final_interview'];

    /** @return array<int, array{key:string, label:string}> */
    public static function stagesFor(string $pipeline): array
    {
        return self::STAGE_FLOWS[$pipeline] ?? self::STAGE_FLOWS['standard'];
    }

    /** @return array<int, string> */
    public static function stageKeysFor(string $pipeline): array
    {
        return array_column(self::stagesFor($pipeline), 'key');
    }

    public function __construct(
        private readonly EmployeeService $employees,
        private readonly AuditService $audit,
    ) {}

    public function listRequisitions(): Collection
    {
        return Requisition::with(['department', 'hiringManager.user'])
            ->withCount('candidates')
            ->latest('opened_at')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createRequisition(array $data): Requisition
    {
        $requisition = Requisition::create([
            'title' => $data['title'],
            'employment_type' => $data['employment_type'] ?? null,
            // Pipeline is derived from employment type (OJT → OJT stage flow).
            'pipeline' => self::pipelineForEmployment($data['employment_type'] ?? null),
            'salary_range' => $data['salary_range'] ?? null,
            'department_id' => $data['department_id'],
            'hiring_manager_id' => $data['hiring_manager_id'] ?? null,
            'openings' => $data['openings'] ?? 1,
            'stage' => $data['stage'] ?? 'active',
            'opened_at' => $data['opened_at'] ?? now(config('app.timezone_local'))->toDateString(),
            'target_start_date' => $data['target_start_date'] ?? null,
            'location' => $data['location'] ?? null,
            'job_description' => $data['job_description'] ?? null,
        ]);

        $this->audit->log('created', 'Recruitment', "Opened requisition '{$requisition->title}'", $requisition);

        return $requisition->load(['department', 'hiringManager.user'])->loadCount('candidates');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateRequisition(Requisition $requisition, array $data): Requisition
    {
        $employmentType = $data['employment_type'] ?? $requisition->employment_type;

        $requisition->update([
            'title' => $data['title'],
            'employment_type' => $employmentType,
            'pipeline' => self::pipelineForEmployment($employmentType),
            'salary_range' => $data['salary_range'] ?? null,
            'department_id' => $data['department_id'],
            'hiring_manager_id' => $data['hiring_manager_id'] ?? null,
            'openings' => $data['openings'],
            'stage' => $data['stage'] ?? $requisition->stage,
            'target_start_date' => $data['target_start_date'] ?? null,
            'location' => $data['location'] ?? null,
            'job_description' => $data['job_description'] ?? null,
        ]);

        $this->audit->log('updated', 'Recruitment', "Updated requisition '{$requisition->title}'", $requisition);

        return $requisition->load(['department', 'hiringManager.user'])->loadCount('candidates');
    }

    public function deleteRequisition(Requisition $requisition): void
    {
        $requisition->delete(); // soft delete

        $this->audit->log('removed', 'Recruitment', "Closed requisition '{$requisition->title}'", $requisition);
    }

    public function candidatesFor(Requisition $requisition): Collection
    {
        return $requisition->candidates()->with('requisition')->latest()->get();
    }

    public function allCandidates(): Collection
    {
        return Candidate::with('requisition.department')->latest()->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createCandidate(array $data, ?\Illuminate\Http\UploadedFile $resume = null): Candidate
    {
        $requisition = Requisition::findOrFail($data['requisition_id']);
        if ($requisition->stage !== 'active') {
            throw ValidationException::withMessages([
                'requisition_id' => ['Candidates can only be added to an active requisition.'],
            ]);
        }

        $stage = $data['stage'] ?? null;

        $resumePath = $resume
            ? $resume->store("hr/resumes/{$requisition->id}", 'local')
            : null;

        $candidate = Candidate::create([
            'requisition_id' => $data['requisition_id'],
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'source' => $data['source'] ?? 'direct',
            'experience_years' => $data['experience_years'] ?? null,
            'stage' => $stage,
            'rating' => $data['rating'] ?? null,
            'expected_salary' => $data['expected_salary'] ?? null,
            'location' => $data['location'] ?? null,
            'feedback' => $data['feedback'] ?? null,
            'notes' => $data['notes'] ?? null,
            'resume_path' => $resumePath,
        ]);

        $this->audit->log('created', 'Recruitment', "Added candidate {$candidate->name} to '{$requisition->title}'", $candidate);

        return $candidate;
    }

    public function moveStage(Candidate $candidate, string $stage): Candidate
    {
        // A committed hire (draft employee already created) is locked in place.
        if ($candidate->hired_employee_id) {
            throw ValidationException::withMessages([
                'stage' => ['This candidate has been hired and can no longer be moved.'],
            ]);
        }

        // The stage must belong to this requisition's pipeline.
        $keys = self::stageKeysFor($candidate->requisition->pipeline);
        if (! in_array($stage, $keys, true)) {
            throw ValidationException::withMessages([
                'stage' => ["'{$stage}' is not a stage in this requisition's pipeline."],
            ]);
        }

        $candidate->update(['stage' => $stage]);

        $label = collect(self::stagesFor($candidate->requisition->pipeline))->firstWhere('key', $stage)['label'] ?? $stage;
        $this->audit->log('updated', 'Recruitment', "Moved candidate {$candidate->name} to {$label}", $candidate);

        return $candidate;
    }

    /** The email types a candidate can be sent, and which stage each belongs to. */
    public const EMAIL_TYPES = ['online_interview', 'final_interview', 'assessment', 'offer', 'rejection'];

    /** The email a candidate at this stage can receive, or null if none. */
    public static function emailTypeForStage(?string $stage): ?string
    {
        return match ($stage) {
            'online_interview' => 'online_interview',
            'final_interview' => 'final_interview',
            'assessment' => 'assessment',
            'hired' => 'offer',
            'rejected' => 'rejection',
            default => null,
        };
    }

    /**
     * Send a stage-specific email to a candidate (online/final interview invite,
     * assessment, job offer, or rejection). The type must match the candidate's current
     * stage. Records the type in `emails_sent` so the board shows per-stage "sent" state.
     * Never fails the action if the mail transport is down.
     *
     * The assessment email is the one HR composes: `$compose` carries the link,
     * instructions and deadline, which are stored on the candidate as the record of what
     * was sent (and so a re-send can repeat it).
     *
     * @param  array{link?: ?string, instructions?: ?string, deadline?: ?string}  $compose
     */
    public function sendEmail(Candidate $candidate, string $type, array $compose = []): Candidate
    {
        if (! in_array($type, self::EMAIL_TYPES, true)) {
            throw ValidationException::withMessages(['type' => ['Unknown email type.']]);
        }
        if (! $candidate->email) {
            throw ValidationException::withMessages([
                'email' => ['This candidate has no email address on file.'],
            ]);
        }
        if (self::emailTypeForStage($candidate->stage) !== $type) {
            throw ValidationException::withMessages([
                'type' => ["This email doesn't match the candidate's current stage."],
            ]);
        }

        if ($type === 'assessment') {
            $link = trim((string) ($compose['link'] ?? '')) ?: null;
            $instructions = trim((string) ($compose['instructions'] ?? '')) ?: null;

            if (! $link && ! $instructions) {
                throw ValidationException::withMessages([
                    'instructions' => ['Give the candidate a link, instructions, or both.'],
                ]);
            }

            // Persisted before sending so the mail renders from the same values HR will
            // see on the candidate afterwards.
            $candidate->update([
                'assessment_link' => $link,
                'assessment_instructions' => $instructions,
                'assessment_deadline' => $compose['deadline'] ?? null,
                'assessment_sent_at' => now(),
            ]);
        }

        $candidate->loadMissing('requisition');

        try {
            Mail::to($candidate->email)->send(
                new CandidateNotificationMail($candidate, $candidate->requisition, $type),
            );
        } catch (\Throwable $e) {
            // Don't fail the action if the mail transport is down — log and carry on.
            report($e);
        }

        $sent = collect($candidate->emails_sent ?? [])->push($type)->unique()->values()->all();
        $candidate->update(['emails_sent' => $sent]);

        $this->audit->log('updated', 'Recruitment', "Sent {$this->emailLabel($type)} email to {$candidate->name}", $candidate);

        return $candidate->fresh('requisition');
    }

    /**
     * Reject a candidate: move to the rejected stage with a reason, and optionally
     * send the rejection email. A hired candidate can't be rejected.
     */
    public function reject(Candidate $candidate, string $reason, ?string $note, bool $sendEmail): Candidate
    {
        if ($candidate->hired_employee_id) {
            throw ValidationException::withMessages(['stage' => ['A hired candidate cannot be rejected.']]);
        }
        if (! in_array('rejected', self::stageKeysFor($candidate->requisition->pipeline), true)) {
            throw ValidationException::withMessages(['stage' => ['This pipeline has no rejected stage.']]);
        }

        $candidate->update([
            'stage' => 'rejected',
            'rejection_reason' => trim($reason . ($note ? " — {$note}" : '')),
            'rejected_at' => now(),
        ]);

        $this->audit->log('updated', 'Recruitment', "Rejected candidate {$candidate->name} ({$reason})", $candidate);

        if ($sendEmail && $candidate->email) {
            return $this->sendEmail($candidate->fresh('requisition'), 'rejection');
        }

        return $candidate->fresh('requisition');
    }

    private function emailLabel(string $type): string
    {
        return match ($type) {
            'online_interview' => 'online interview',
            'final_interview' => 'final interview',
            'assessment' => 'assessment',
            'offer' => 'offer',
            'rejection' => 'rejection',
            default => $type,
        };
    }

    /**
     * The handoff automation: creates a draft employee (login + record + leave
     * balances) from the candidate's application, then closes the seat. Called
     * explicitly via the "create draft record" button — NOT by dragging into the
     * Hired column. Idempotent: a candidate already linked to an employee is a no-op.
     */
    public function hire(Candidate $candidate): Candidate
    {
        if ($candidate->hired_employee_id) {
            return $candidate;
        }

        $requisition = $candidate->requisition;
        if ($requisition->stage !== 'active' || $requisition->openings < 1) {
            throw ValidationException::withMessages([
                'candidate' => ['This requisition has no open positions to hire into.'],
            ]);
        }

        return DB::transaction(function () use ($candidate, $requisition) {

            $employee = $this->employees->create([
                'name' => $candidate->name,
                'email' => $this->uniqueEmail($candidate),
                'department_id' => $requisition->department_id,
                'job_title' => $requisition->title,
                'employment_status' => 'probationary',
                // New hire carries the "Onboarding" tag until HR marks it complete
                // (Slice 17). Applies to part-timers/OJT too.
                'onboarding_status' => 'pending',
                'hire_date' => now(config('app.timezone_local'))->toDateString(),
                'base_salary' => 0, // draft — HR sets pay after
            ]);

            $candidate->update([
                'stage' => 'hired',
                'hired_employee_id' => $employee->id,
            ]);

            // Fill the seat; close the requisition when no openings remain.
            $remaining = max(0, $requisition->openings - 1);
            $requisition->update([
                'openings' => $remaining,
                'stage' => $remaining === 0 ? 'closed' : $requisition->stage,
            ]);

            $this->audit->log('approved', 'Recruitment', "Hired {$candidate->name}", $candidate);

            return $candidate->fresh();
        });
    }

    /** Use the candidate's email if unique; otherwise derive one from their name. */
    private function uniqueEmail(Candidate $candidate): string
    {
        if ($candidate->email && ! User::where('email', $candidate->email)->exists()) {
            return $candidate->email;
        }

        $base = Str::slug($candidate->name, '.');
        $email = "{$base}@viteseo.ph";
        $i = 1;
        while (User::where('email', $email)->exists()) {
            $email = "{$base}{$i}@viteseo.ph";
            $i++;
        }

        return $email;
    }
}
