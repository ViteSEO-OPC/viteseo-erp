<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Requests\Concerns\ValidatesDesignLayout;
use App\Modules\Crm\Support\EmailDesign\EmailDesignSchema;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Renaming a reusable email design, or replacing its layout. Whether the new layout still fits every template
 * that uses the design is decided by EmailDesignService, which knows those templates.
 */
class UpdateEmailDesignRequest extends FormRequest
{
    use ValidatesDesignLayout;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:'.EmailDesignSchema::MAX_DESIGN_NAME],
            'layout' => ['sometimes', 'required', 'string'],
            // Also point every designed template in the organization at this design. The count is the number of
            // designed templates the admin confirmed; the save is refused if it has changed.
            'apply_to_all' => ['sometimes', 'boolean'],
            'apply_to_all_count' => ['required_if_accepted:apply_to_all', 'integer', 'min:0'],
        ];
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
