<?php

declare(strict_types=1);

namespace App\Modules\Crm\Notifications\Presenters;

/**
 * `inbound_reply` — a prospect replied and the mail was matched to a lead.
 * Payload written by InboundReplyNotification.
 *
 * Presentation is unchanged from the previous match arms in NotificationResource;
 * only its home moved.
 */
final class InboundReplyPresenter implements NotificationPresenter
{
    use LinksToLead;

    /**
     * Who replied leads the title — that is what makes the row scannable in a
     * list. Falls back through name → address → a generic noun, because older
     * rows may carry only one of them.
     *
     * @param  array<string, mixed>  $data
     */
    public function title(array $data): string
    {
        return 'New reply from '.(string) ($data['from_name'] ?? $data['from'] ?? 'a prospect');
    }

    /**
     * Subject first, body preview second. Empty string collapses to null so the UI
     * omits the line entirely rather than rendering a blank one.
     *
     * @param  array<string, mixed>  $data
     */
    public function message(array $data): ?string
    {
        return (string) ($data['subject'] ?? $data['preview'] ?? '') ?: null;
    }

    /** @param array<string, mixed> $data */
    public function link(array $data): ?string
    {
        // The reply lives on the lead's email thread, so the lead is the target.
        return $this->leadLink($data);
    }
}
