<?php

namespace App\Modules\Crm\Resources;

use App\Modules\Crm\Models\Email;
use Illuminate\Http\Request;

/**
 * One message of the Lead Details Email Conversation: {@see EmailResource} plus the HTML it was sent with.
 *
 * A SEPARATE RESOURCE, NOT A FIELD ON EmailResource, because EmailResource is also the drafts list, every
 * send/schedule response and the automation (n8n) response — none of which displays a message body as an
 * email. A designed email's HTML can be up to 90 KB, so it travels only to the one panel that shows it.
 *
 * @mixin Email
 */
class LeadThreadEmailResource extends EmailResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            // The text/html part exactly as it was sent (see OutreachEmailService::sendExisting), or null
            // for a plain-text, failed, inbound or older email, which keep rendering `body` as before.
            // Server-written markup, shown only inside the thread's sandboxed EmailHtmlFrame.
            'bodyHtml' => $this->body_html,
        ];
    }
}
