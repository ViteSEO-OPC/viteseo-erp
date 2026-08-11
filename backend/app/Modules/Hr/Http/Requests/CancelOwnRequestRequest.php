<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Withdrawing a claim, payroll inquiry or concern.
 *
 * Ownership and status rules live in the service (`CancelsOwnRequest`) — this only insists on the
 * reason, which is the whole point: a cancelled record with no explanation is indistinguishable
 * from one that vanished.
 */
class CancelOwnRequestRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Please say why you are cancelling this request.',
        ];
    }
}
