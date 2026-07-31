<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Support\NotificationInbox;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The lone `scope` parameter, for the endpoints that take it WITHOUT the feed's
 * filters — today that is the unread count.
 *
 * Separate from {@see IndexNotificationRequest} rather than reused: sharing that class
 * would advertise `search`, `type` and `per_page` on an endpoint that returns a single
 * integer and silently ignores all three. A caller passing them would get a 200 and no
 * hint that nothing happened.
 *
 * Both classes take the accepted values from {@see NotificationInbox::SCOPES}, so the
 * two endpoints cannot disagree about what a scope is — which matters here more than
 * usual, because the badge this validates and the feed the other validates have to
 * describe the same set of rows.
 */
class NotificationScopeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'scope' => ['nullable', Rule::in(NotificationInbox::SCOPES)],
        ];
    }
}
