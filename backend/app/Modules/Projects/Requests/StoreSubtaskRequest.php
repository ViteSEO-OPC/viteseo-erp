<?php

declare(strict_types=1);

namespace App\Modules\Projects\Requests;

use App\Modules\Projects\Enums\TaskPriority;
use App\Modules\Projects\Models\ProjectStatus;
use App\Modules\Projects\Support\PublicId;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubtaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
            'deadline' => ['nullable', 'date'],
        ];
    }
}
