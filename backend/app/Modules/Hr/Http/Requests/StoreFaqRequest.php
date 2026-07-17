<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Services\FaqService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFaqRequest extends FormRequest
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
            'question' => ['required', 'string', 'max:255'],
            'answer' => ['required', 'string', 'max:5000'],
            'category' => ['required', Rule::in(FaqService::CATEGORIES)],
            'status' => ['nullable', Rule::in(FaqService::STATUSES)],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
            'is_pinned' => ['boolean'],
        ];
    }
}
