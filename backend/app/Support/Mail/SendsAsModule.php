<?php

namespace App\Support\Mail;

/**
 * Gives a Mailable or Notification its module's sender identity.
 *
 * The resolver is pulled from the container at send time rather than injected and
 * held as state: Mailables and queued Notifications are serialized onto the queue,
 * and a serialized config-reading service would carry a stale identity across a
 * config change. Resolving on use keeps the identity current.
 */
trait SendsAsModule
{
    protected function moduleSender(Module $module): ModuleSender
    {
        return app(ModuleSenderResolver::class)->for($module);
    }
}
