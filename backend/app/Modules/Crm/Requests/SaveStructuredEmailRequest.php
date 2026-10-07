<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Requests\Concerns\ValidatesDesignedReply;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Save a per-lead structured email draft: its subject, optional recipient, design and content. The content is a
 * JSON string — the same shape Settings saves a template's content in — and is held to the template rules by
 * StructuredEmailService. The design must be one of this organization's live designs.
 */
class SaveStructuredEmailRequest extends FormRequest
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
            'subject' => ['required', 'string', 'max:255'],
            'to' => ['nullable', 'email', 'max:255'],
            'email_design_id' => ['required', ...$this->emailDesignIdRules()],
            'content' => ['required', 'string'],
        ];
    }
}
