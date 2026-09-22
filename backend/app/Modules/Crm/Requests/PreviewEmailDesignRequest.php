<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Requests\Concerns\ValidatesDesignLayout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Previewing an unsaved design layout, shown with sample content. Refused for exactly what saving the layout
 * would refuse.
 */
class PreviewEmailDesignRequest extends FormRequest
{
    use ValidatesDesignLayout;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['layout' => ['required', 'string']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->layoutMessages();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->validateLayout($validator));
    }
}
