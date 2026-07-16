<?php

namespace App\Modules\Crm\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Bulk restore request (Archive Management). Serves BOTH archive UI actions through one endpoint:
 *   - "Restore Selected" — pass explicit `ids` (the checked rows).
 *   - "Restore all filtered" — omit `ids`; the current filter set (search/year/status/assigned_bd/
 *     archived_by) defines the batch.
 * Either way the controller restores each matched lead through the SAME single-record restore path,
 * so there is exactly one restore workflow and one Activity logging path.
 */
class RestoreAllRequest extends FormRequest
{
    private const STATUSES = ['interested', 'awaiting-reply', 'declined', 'call-back-req', 'new'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            // Explicit selection (optional). When present, only these archived leads are restored.
            'ids' => ['nullable', 'array'],
            'ids.*' => ['integer'],
            // Same filters as the list, so a filter-scoped "restore all" restores exactly what the
            // user is viewing.
            'search' => ['nullable', 'string', 'max:255'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'status' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                foreach (explode(',', (string) $value) as $token) {
                    if (! in_array(trim($token), self::STATUSES, true)) {
                        $fail('The selected status is invalid.');

                        return;
                    }
                }
            }],
            'assigned_bd' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if ($value !== 'unassigned' && ! ctype_digit((string) $value)) {
                    $fail('The selected BD is invalid.');
                }
            }],
            'archived_by' => ['nullable', 'integer'],
        ];
    }
}
