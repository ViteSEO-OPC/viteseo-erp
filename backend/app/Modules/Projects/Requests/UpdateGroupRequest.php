<?php

declare(strict_types=1);

namespace App\Modules\Projects\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('group')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // workspace_id is immutable — a group cannot be moved between workspaces.
            'workspace_id' => ['prohibited'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'color' => ['sometimes', 'required', 'string', 'max:32'],
            'leader_id' => ['sometimes', 'required', 'integer', 'exists:users,id'],
            'archived' => ['sometimes', 'boolean'],
        ];
    }
}
