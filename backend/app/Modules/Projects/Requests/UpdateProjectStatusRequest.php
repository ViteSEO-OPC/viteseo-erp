<?php

declare(strict_types=1);

namespace App\Modules\Projects\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('status')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'order' => ['sometimes', 'integer'],
            // key and is_protected cannot be changed.
            'key' => ['prohibited'],
            'is_protected' => ['prohibited'],
        ];
    }
}
