<?php

declare(strict_types=1);

namespace App\Modules\Projects\Requests;

use App\Modules\Projects\Concerns\ChecksDeadlineDates;
use App\Modules\Projects\Enums\AssignmentType;
use App\Modules\Projects\Enums\TaskPriority;
use App\Modules\Projects\Models\Group;
use App\Modules\Projects\Models\ProjectStatus;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Support\PublicId;
use App\Support\OrgUserRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaskRequest extends FormRequest
{
    use ChecksDeadlineDates;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('task')) ?? false;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            /** @var Task $task */
            $task = $this->route('task');

            // Effective values after this update (fall back to current for
            // fields not present in the payload).
            $start = $this->has('start_date')
                ? $this->asDate($this->input('start_date'))
                : $this->asDate($task->start_date?->toDateString());
            $deadline = $this->has('deadline')
                ? $this->asDate($this->input('deadline'))
                : $this->asDate($task->deadline?->toDateString());

            if ($start && $deadline && $deadline->lessThan($start)) {
                $v->errors()->add('deadline', 'The due date must be on or after the start date.');
            }

            if ($this->has('deadline')) {
                $this->flagPastDeadline(
                    $v,
                    $this->asDate($this->input('deadline')),
                    $this->asDate($task->deadline?->toDateString()),
                );
            }
        });
    }

    /**
     * Translate public UUIDs (status, group) into internal ids before validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('status_id')) {
            $this->merge(['status_id' => PublicId::toInternalId(ProjectStatus::class, $this->input('status_id'))]);
        }
        if ($this->has('group_id')) {
            $this->merge(['group_id' => PublicId::toInternalId(Group::class, $this->input('group_id'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'deadline' => ['sometimes', 'nullable', 'date'],
            'assignment_type' => ['sometimes', 'required', Rule::in(array_column(AssignmentType::cases(), 'value'))],
            'priority' => ['sometimes', 'required', Rule::in(array_column(TaskPriority::cases(), 'value'))],
            'status_id' => ['sometimes', 'required', 'integer', 'exists:pm_project_statuses,id'],
            'group_id' => [
                'sometimes',
                'required_if:assignment_type,group',
                'prohibited_unless:assignment_type,group',
                'integer',
                'exists:pm_groups,id',
            ],
            'assignee_id' => [
                'sometimes',
                // Individual tasks may be unassigned (and become so on a type change).
                'nullable',
                'prohibited_unless:assignment_type,individual',
                'integer',
                OrgUserRule::exists(),
            ],
            // Effort runs on a fixed 0–10 scale (matches the UI slider).
            'effort_score' => [
                'sometimes',
                'nullable',
                'integer',
                'min:0',
                'max:10',
            ],
        ];
    }
}
