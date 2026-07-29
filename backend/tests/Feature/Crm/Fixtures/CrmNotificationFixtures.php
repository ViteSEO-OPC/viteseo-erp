<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Notification fixtures — declared INSIDE the CRM namespace on purpose
|--------------------------------------------------------------------------
|
| BroadcastCrmNotification decides whether to broadcast by testing the
| notification's namespace, so a fixture declared in `Tests\…` would be filtered
| out and every assertion about it would pass for the wrong reason — including
| the negative ones. These classes therefore live in the namespace under test.
|
| The file is `require_once`d rather than autoloaded: the namespace maps to
| `app/Modules/Crm/Notifications`, and test doubles do not belong in shipped
| code. Nothing outside the notification tests should use them.
|
*/

namespace App\Modules\Crm\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A CRM in-app notification of no particular type. Its payload is supplied per
 * test, which is what lets one class stand in for "a type nobody has taught the
 * system about yet".
 */
class FixtureCrmNotification extends Notification
{
    /** @param array<string, mixed> $payload */
    public function __construct(private readonly array $payload = []) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return $this->payload;
    }
}

/** A CRM notification that never reaches the in-app feed — mail delivers itself. */
class FixtureCrmMailNotification extends Notification
{
    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->line('fixture');
    }
}
