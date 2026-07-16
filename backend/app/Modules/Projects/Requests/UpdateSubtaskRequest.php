<?php

declare(strict_types=1);

namespace App\Modules\Projects\Requests;

use App\Modules\Projects\Concerns\ChecksDeadlineDates;
use App\Modules\Projects\Enums\TaskPriority;
use App\Modules\Projects\Models\ProjectStatus;
use App\Modules\Projects\Models\Subtask;
use App\Modules\Projects\Support\PublicId;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSubtaskRequest extends FormRequest
{
    use ChecksDeadlineDates;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('subtask')) ?? false;
    }

    public function withValidator(Validator $validator): void
    {
        // Only validate the deadline when it's actually being changed — otherwise
        // an unrelated update (e.g. a status change) on a subtask whose existing
        // deadline predates these rules would be wrongly rejected.
        if (! $this->has('deadline')) {
            return;
        }

        $validator->after(function (Validator $v): void {
            /** @var Subtask|null $subtask */
            $subtask = $this->route('subtask');

            $deadline = $this->asDate($this->input('deadline'));
            $parentDeadline = $this->asDate($subtask?->task?->deadline?->toDateString());

            if ($deadline && $parentDeadline && $deadline->greaterThan($parentDeadline)) {
                $v->errors()->add('deadline', 'A subtask’s due date can’t be after its task’s due date.');
            }

            $this->flagPastDeadline(
                $v,
                $deadline,
                $this->asDate($subtask?->deadline?->toDateString()),
            );
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'assignee_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'status_id' => ['sometimes', 'required', 'integer', 'exists:pm_project_statuses,id'],
            'priority' => ['sometimes', 'required', Rule::in(array_column(TaskPriority::cases(), 'value'))],
            'effort_score' => ['sometimes', 'required', 'integer', 'min:0'],
            'deadline' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
