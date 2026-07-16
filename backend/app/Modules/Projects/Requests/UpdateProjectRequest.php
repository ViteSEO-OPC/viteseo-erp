<?php

declare(strict_types=1);

namespace App\Modules\Projects\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('project')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // workspace_id is immutable — a project cannot be moved between workspaces.
            'workspace_id' => ['prohibited'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'priority' => ['sometimes', 'nullable', 'string', 'in:low,medium,high'],
            'color' => ['sometimes', 'nullable', 'string', 'max:7'],
            // Lifecycle state — flip a project to "done" (or reopen it).
            'status' => ['sometimes', 'string', 'in:active,done'],
        ];
    }
}
