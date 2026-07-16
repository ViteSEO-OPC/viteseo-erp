<?php

namespace App\Modules\Crm\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexLeadRequest extends FormRequest
{
    /** Frozen status enum — the only values the read-only status filter accepts. */
    private const STATUSES = ['interested', 'awaiting-reply', 'declined', 'call-back-req', 'new'];

    /** Whitelisted sort keys (human labels live in the frontend). Unknown/absent → newest first. */
    private const SORTS = ['recent', 'oldest', 'score-desc', 'score-asc', 'name-asc', 'name-desc'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            // Priority is an independent column (high|medium|low) — unrelated to the frozen status model.
            'priority' => ['nullable', Rule::in(['high', 'medium', 'low'])],
            // Website-condition column — independent filter powering the Website Status overview
            // cards' drill-down. Separate from priority/status.
            'website_status' => ['nullable', Rule::in(['has-website', 'no-website', 'needs-redesign'])],
            // Read-only filter over the frozen status enum. Accepts a comma-separated list so a
            // summary tab can map to multiple statuses (e.g. CONTACTED = awaiting-reply,call-back-req).
            // Single values remain valid (backward compatible). Does NOT change the status model.
            'status' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                foreach (explode(',', (string) $value) as $token) {
                    if (! in_array(trim($token), self::STATUSES, true)) {
                        $fail('The selected status is invalid.');

                        return;
                    }
                }
            }],
            // Sort order over a fixed whitelist. Presentation-only labels ("Most recent", …) map
            // to these keys on the frontend; the column/direction mapping lives in the controller.
            'sort' => ['nullable', Rule::in(self::SORTS)],
            // Raw `industry` filter — comma-separated free-text values (no enum: industry is raw
            // imported data). The canonical niche→industry mapping lives on the frontend for now.
            'industry' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
