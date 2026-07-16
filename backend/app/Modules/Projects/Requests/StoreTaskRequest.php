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
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
{
    use ChecksDeadlineDates;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Task::class) ?? false;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['deadline.after_or_equal' => 'The due date can’t be in the past.'];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $start = $this->asDate($this->input('start_date'));
            $deadline = $this->asDate($this->input('deadline'));
            if ($start && $deadline && $deadline->lessThan($start)) {
                $v->errors()->add('deadline', 'The due date must be on or after the start date.');
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
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'start_date' => ['nullable', 'date'],
            'deadline' => ['nullable', 'date', 'after_or_equal:today'],
            'assignment_type' => ['required', Rule::in(array_column(AssignmentType::cases(), 'value'))],
            'priority' => ['required', Rule::in(array_column(TaskPriority::cases(), 'value'))],
            'status_id' => ['required', 'integer', 'exists:pm_project_statuses,id'],
            'group_id' => [
                'required_if:assignment_type,group',
                'prohibited_unless:assignment_type,group',
                'integer',
                'exists:pm_groups,id',
            ],
            'assignee_id' => [
                // Individual tasks may be left unassigned (default), so this is optional.
                'nullable',
                'prohibited_unless:assignment_type,individual',
                'integer',
                'exists:users,id',
            ],
            'effort_score' => [
                'nullable',
                'integer',
                'min:0',
            ],
        ];
    }
}
