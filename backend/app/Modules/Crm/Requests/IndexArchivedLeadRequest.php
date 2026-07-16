<?php

namespace App\Modules\Crm\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Filters for the Archive Management list (soft-deleted leads). Route-gated to Admin/Super Admin,
 * so authorize() defers to the route middleware/policy. The filter set mirrors the archive UI top
 * bar: search, year (on the archive date), status, assigned BD, and archived-by.
 */
class IndexArchivedLeadRequest extends FormRequest
{
    /** Frozen status enum — same set the primary leads list accepts. */
    private const STATUSES = ['interested', 'awaiting-reply', 'declined', 'call-back-req', 'new'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            // Calendar year of the archive date (deleted_at). Bounded to a sane range.
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            // Comma-separated list over the frozen status enum (single values remain valid).
            'status' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                foreach (explode(',', (string) $value) as $token) {
                    if (! in_array(trim($token), self::STATUSES, true)) {
                        $fail('The selected status is invalid.');

                        return;
                    }
                }
            }],
            // Owning BD filter: a user id, or the literal 'unassigned' (leads with no owner).
            'assigned_bd' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if ($value !== 'unassigned' && ! ctype_digit((string) $value)) {
                    $fail('The selected BD is invalid.');
                }
            }],
            // Archived-by filter: the user id who archived the lead (deleted-Activity causer).
            'archived_by' => ['nullable', 'integer'],
        ];
    }
}
