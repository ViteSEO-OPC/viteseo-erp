<?php

declare(strict_types=1);

namespace App\Modules\Projects\Requests;

use App\Modules\Projects\Enums\TaskPriority;
use App\Modules\Projects\Models\ProjectStatus;
use App\Modules\Projects\Support\PublicId;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSubtaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('subtask')) ?? false;
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
