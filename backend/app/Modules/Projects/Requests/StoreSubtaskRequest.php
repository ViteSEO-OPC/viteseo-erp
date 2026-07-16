<?php

declare(strict_types=1);

namespace App\Modules\Projects\Requests;

use App\Modules\Projects\Concerns\ChecksDeadlineDates;
use App\Modules\Projects\Enums\TaskPriority;
use App\Modules\Projects\Models\ProjectStatus;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Support\PublicId;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubtaskRequest extends FormRequest
{
    use ChecksDeadlineDates;

    public function authorize(): bool
    {
        return true;
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
            /** @var Task|null $task */
            $task = $this->route('task');
            $deadline = $this->asDate($this->input('deadline'));
            $parentDeadline = $this->asDate($task?->deadline?->toDateString());
            if ($deadline && $parentDeadline && $deadline->greaterThan($parentDeadline)) {
                $v->errors()->add('deadline', 'A subtask’s due date can’t be after its task’s due date.');
            }
        });
    }

    /**
     * Translate the public status UUID into its internal id before validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('status_id')) {
            $this->merge(['status_id' => PublicId::toInternalId(ProjectStatus::class, $this->input('status_id'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            // Subtasks may be created unassigned (default).
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            'status_id' => ['required', 'integer', 'exists:pm_project_statuses,id'],
            'priority' => ['required', Rule::in(array_column(TaskPriority::cases(), 'value'))],
            'effort_score' => ['required', 'integer', 'min:0'],
            'deadline' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }
}
