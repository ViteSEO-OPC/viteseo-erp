<?php

declare(strict_types=1);

namespace App\Modules\Projects\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Approving a page-worth of proposals in one go. Carries public UUIDs, which
 * the service resolves — anything already approved, or not a real proposal, is
 * reported back as skipped rather than failing the whole batch.
 *
 * Authorization is the controller's (`approve` on the task/subtask policy).
 */
class BulkApproveRequest extends FormRequest
{
    /** Upper bound on one batch — well above a full page of the review queue. */
    public const MAX_IDS = 100;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_IDS],
            'ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.required' => 'Select at least one submission to approve.',
            'ids.max' => 'Approve at most '.self::MAX_IDS.' submissions at a time.',
        ];
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        /** @var list<string> $ids */
        $ids = $this->validated()['ids'];

        return $ids;
    }
}
