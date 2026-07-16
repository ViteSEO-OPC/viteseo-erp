<?php

declare(strict_types=1);

namespace App\Modules\Projects\Requests;

use App\Modules\Projects\Models\Group;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Support\PublicId;
use Illuminate\Foundation\Http\FormRequest;

class StoreGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Group::class) ?? false;
    }

    /**
     * Translate the public project UUID into its internal id before validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('project_id')) {
            $this->merge([
                'project_id' => PublicId::toInternalId(Project::class, $this->input('project_id')),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'exists:pm_projects,id'],
            'name' => ['required', 'string', 'max:255'],
            'color' => ['required', 'string', 'max:32'],
            'leader_id' => ['required', 'integer', 'exists:users,id'],
            'archived' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null)
    {
        $data = parent::validated();
        $data['archived'] = (bool) ($data['archived'] ?? false);

        return $data;
    }
}
