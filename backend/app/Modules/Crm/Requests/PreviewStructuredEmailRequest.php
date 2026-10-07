<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Requests\Concerns\ValidatesDesignedReply;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Preview a per-lead structured email: a design and a content document, rendered for this lead and sender
 * exactly as the send would render them. Writes nothing.
 */
class PreviewStructuredEmailRequest extends FormRequest
{
    use ValidatesDesignedReply;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'email_design_id' => ['required', ...$this->emailDesignIdRules()],
            'content' => ['required', 'string'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
