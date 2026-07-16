<?php

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            // Optional override; defaults to the lead's email in the service.
            'to' => ['nullable', 'email', 'max:255'],
            // RC3 Send-Later: when present (and future), the email is SCHEDULED instead of sent now.
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
