<?php

declare(strict_types=1);

namespace App\Modules\Projects\Requests;

use App\Modules\Projects\Models\ProjectStatus;
use Illuminate\Foundation\Http\FormRequest;

class StoreProjectStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ProjectStatus::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Hex color for the status chip/column, e.g. #4fdbd7.
            'color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'order' => ['nullable', 'integer'],
            // key and is_protected are set by the Service, never from input.
            'key' => ['prohibited'],
            'is_protected' => ['prohibited'],
        ];
    }
}
