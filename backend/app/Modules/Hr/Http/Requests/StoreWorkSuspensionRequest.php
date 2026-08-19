<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Declaring (or amending) a non-working day.
 *
 * `is_paid` is required on create and has no default: whether the company still pays for a
 * cancelled day is the entire decision being recorded, and a default would let it be made by
 * omission. On an amendment it is optional, so a caller can rename a day without restating it.
 */
class StoreWorkSuspensionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role:hr_officer|super_admin on the route
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $amending = $this->route('workSuspension') !== null;

        return [
            'date' => [$amending ? 'prohibited' : 'required', 'date'],
            'name' => [$amending ? 'sometimes' : 'required', 'string', 'max:255'],
            'is_paid' => [$amending ? 'sometimes' : 'required', 'boolean'],
            // Unlike `is_paid`, this one HAS a safe default (false = the time clock behaves as
            // it always has), so it is optional on create rather than required: HR declaring a
            // shutdown in a hurry should not be stopped to answer a question whose omission
            // changes nothing.
            'work_requires_request' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // Moving a declaration to a different date is two decisions wearing one button:
            // withdrawing the old day and declaring a new one. Both are audited separately.
            'date.prohibited' => 'To move a suspension to another date, withdraw this one and declare the new date.',
            'is_paid.required' => 'Say whether this day is paid — it decides whether the day costs a day’s pay.',
        ];
    }
}
