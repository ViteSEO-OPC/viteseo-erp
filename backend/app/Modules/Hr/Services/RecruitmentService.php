<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\Candidate;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Requisition;
use App\Modules\Hr\Services\Concerns\PagesQueues;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecruitmentService
{
    use PagesQueues;

    public const SOURCES = ['referral', 'linkedin', 'jobboard', 'direct', 'agency'];
    // draft = not open yet · active = open (add/hire allowed) · closed = filled/done.
    public const REQ_STAGES = ['draft', 'active', 'closed'];

    public const PIPELINES = ['standard', 'ojt'];

    /** The same set as the employee record, so a hire lands on the type the role was opened for. */
    public const EMPLOYMENT_TYPES = EmployeeService::STATUSES;

    /** How an OJT allowance is quoted. */
    public const ALLOWANCE_PERIODS = ['per_day', 'per_hour', 'per_month'];

    /** The Intern Allowance calculation method a requisition's quoted period is read in. */
    private const METHOD_FOR_PERIOD = [
        'per_day' => 'per_day',
        'per_hour' => 'per_hour',
        'per_month' => 'fixed_monthly',
    ];

    /** The employment type a hire from this requisition starts on. */
    private function hireTypeFor(Requisition $requisition): string
    {
        if ($requisition->pipeline === 'ojt') {
            return 'ojt';
        }

        $type = $requisition->employment_type;

        return $type === null || $type === 'regular' || ! in_array($type, EmployeeService::STATUSES, true)
            ? 'probationary'
            : $type;
    }

    /** Employment type decides the candidate pipeline (OJT gets the OJT flow). */
    public static function pipelineForEmployment(?string $employmentType): string
    {
        return $employmentType === 'ojt' ? 'ojt' : 'standard';
    }

    /**
     * The compensation a requisition quotes: an OJT pays an allowance, every other
     * employment type quotes a salary range. Returned as the whole set rather than the
     * one live field, so the other side is always cleared -- a requisition switched from
     * full-time to OJT must stop quoting the salary it was opened with, and a figure left
     * behind in the dead column would be read back by anything that looks at it later.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function compensationFor(?string $employmentType, array $data): array
    {
        if ($employmentType !== 'ojt') {
            return [
                'salary_range' => $data['salary_range'] ?? null,
                'allowance_amount' => null,
                'allowance_period' => null,
            ];
        }

        $amount = $data['allowance_amount'] ?? null;

        return [
            'salary_range' => null,
            'allowance_amount' => $amount,
            // A period on its own says nothing -- it only qualifies a figure.
            'allowance_period' => $amount === null ? null : ($data['allowance_period'] ?? null),
        ];
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
        private readonly InternAllowanceService $internAllowance,
    ) {}

    /**
     * One page of the requisition board, newest opening first.
     *
     * A requisition tracks its progress in `stage`, not `status` — so the pills filter on that
     * column. No priority rung: a requisition's stages are a pipeline, not a decision queue,
     * and floating one of them would be an opinion about hiring rather than about paging.
     *
     * @param  array<string, mixed>  $filters  search, status (stage), page, per_page
     */
    public function listRequisitions(array $filters = []): LengthAwarePaginator
    {
        return $this->paginateQueue(
            Requisition::with(['department', 'hiringManager.user'])->withCount('candidates'),
            $filters,
            ['title', 'location', 'employment_type', 'job_description'],
            searchEmployee: false,
            recencyColumn: 'opened_at',
            statusColumn: 'stage',
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createRequisition(array $data): Requisition
    {
        $employmentType = $data['employment_type'] ?? null;

        $requisition = Requisition::create([
            'title' => $data['title'],
            'employment_type' => $employmentType,
            // Pipeline is derived from employment type (OJT → OJT stage flow).
            'pipeline' => self::pipelineForEmployment($employmentType),
            ...$this->compensationFor($employmentType, $data),
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
            ...$this->compensationFor($employmentType, $data),
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

        /*
         * The school and the practicum hours it requires belong to an OJT application and
         * nothing else. Guarded here rather than trusted from the form, so a payload aimed
         * at a standard requisition cannot leave a stray school on a candidate whose board
         * has nowhere to show it.
         */
        $isOjt = $requisition->pipeline === 'ojt';

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
            'school' => $isOjt ? ($data['school'] ?? null) : null,
            'required_hours' => $isOjt ? ($data['required_hours'] ?? null) : null,
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
     * Reject a candidate: move to the rejected stage with a reason. A hired candidate
     * can't be rejected.
     */
    public function reject(Candidate $candidate, string $reason, ?string $note): Candidate
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

        return $candidate->fresh('requisition');
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
                /*
                 * An OJT requisition hires an INTERN. This was hardcoded to probationary,
                 * which meant the OJT pipeline -- its own board, its own MOA-review and
                 * role-assignment stages -- handed over someone the rest of HR then treated
                 * as a probationary employee: wrong leave allotment, and no way to tell an
                 * intern from a new hire on the directory.
                 *
                 * Otherwise the hire takes the requisition's type — except a regular opening,
                 * which starts on probation as the Labor Code allows. An untyped requisition
                 * keeps the old probationary default.
                 */
                'employment_status' => $this->hireTypeFor($requisition),
                // Carried from the application. The school goes into the education field the
                // record already has rather than a second column saying the same thing.
                'university' => $candidate->school,
                'ojt_required_hours' => $candidate->required_hours,
                /*
                 * The allowance the requisition quoted becomes their own — but only when it is
                 * quoted in the unit the company calculates in. ₱8,000 a month read as ₱8,000 a
                 * day is not a figure to hand payroll; left null, they get the company default
                 * until HR sets theirs.
                 */
                'allowance_amount' => $requisition->pipeline === 'ojt'
                    && $requisition->allowance_amount !== null
                    && (self::METHOD_FOR_PERIOD[$requisition->allowance_period] ?? null)
                        === $this->internAllowance->asOf()->get('method')
                    ? $requisition->allowance_amount
                    : null,
                // New hire carries the "Onboarding" tag until HR marks it complete
                // . Applies to part-timers/OJT too.
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
