<?php

declare(strict_types=1);

namespace App\Modules\Projects\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Rejecting a proposal always carries a reason — the author sees it and revises
 * against it, so an empty rejection is not useful feedback.
 *
 * Authorization is the controller's (`approve` on the task/subtask policy).
 */
class RejectApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Tell the author why this is being rejected.',
        ];
    }
}
