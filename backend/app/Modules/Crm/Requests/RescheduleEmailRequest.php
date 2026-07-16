<?php

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reschedule a still-scheduled outbound email (RC3 Send-Later). Authorization is on the route
 * (EmailPolicy@reschedule — author/Admin, scheduled-only).
 */
class RescheduleEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'scheduled_at' => ['required', 'date', 'after:now'],
        ];
    }
}
