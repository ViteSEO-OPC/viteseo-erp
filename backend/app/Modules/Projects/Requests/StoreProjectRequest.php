<?php

declare(strict_types=1);

namespace App\Modules\Projects\Requests;

use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Workspace;
use App\Modules\Projects\Support\PublicId;
use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Project::class) ?? false;
    }

    /**
     * Translate the public workspace UUID into its internal id before validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('workspace_id')) {
            $this->merge([
                'workspace_id' => PublicId::toInternalId(Workspace::class, $this->input('workspace_id')),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'workspace_id' => ['required', 'integer', 'exists:pm_workspaces,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority' => ['nullable', 'string', 'in:low,medium,high'],
            'color' => ['nullable', 'string', 'max:7'],
        ];
    }
}
