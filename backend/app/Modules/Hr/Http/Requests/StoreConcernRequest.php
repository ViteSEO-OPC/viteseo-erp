<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Services\ConcernService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConcernRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::in(ConcernService::CATEGORIES)],
            'description' => ['required', 'string', 'max:5000'],
            'is_anonymous' => ['boolean'],
        ];
    }
}
