<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Enums\NotificationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query parameters for the Notification Center feed.
 *
 * Every parameter is optional and every one is whitelisted here, so the controller
 * applies only values that have already been validated — an unknown `type` is a 422,
 * never a silently empty feed that looks like "you have no notifications".
 *
 * No `assigned_to`-style ownership parameter exists, and none should: the feed is
 * always the authenticated user's own, resolved from the token. There is deliberately
 * no way to ask for someone else's notifications.
 */
class IndexNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            // Read state. `all` is the default and is accepted explicitly so the UI can
            // round-trip its own filter value without special-casing the default.
            'filter' => ['nullable', Rule::in(['all', 'unread'])],

            // Catalog type. Validated against the enum, so the whitelist cannot drift
            // from the catalog: adding a type makes it filterable automatically, and a
            // retired type stops being accepted the moment its case is removed.
            'type' => ['nullable', Rule::enum(NotificationType::class)],

            // Free text over the stored payload. Bounded because it becomes a LIKE
            // scan; an unbounded term is a cheap way to make the query expensive.
            'search' => ['nullable', 'string', 'max:100'],

            // Page size. Capped so a caller cannot ask for the entire history in one
            // response — the feed is unbounded over a user's lifetime.
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],

            // Pagination strategy, opt-in. Absent means `page` — the offset envelope
            // the bell and dropdown already consume, unchanged. History asks for
            // `cursor` because it pages a feed that grows at the HEAD: with offsets,
            // a notification arriving while the reader is on page 2 pushes every row
            // down one, so the next page repeats a row the reader already saw. A
            // cursor is anchored to a row rather than a count, so head insertions
            // cannot shift it.
            'paginate' => ['nullable', Rule::in(['page', 'cursor'])],
        ];
    }
}
