<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAttachmentRequest extends FormRequest
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
            'file' => ['required', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
            'attachable_type' => ['required', 'in:invoice,bill'],
            'attachable_id' => ['required', 'integer'],
        ];
    }
}
