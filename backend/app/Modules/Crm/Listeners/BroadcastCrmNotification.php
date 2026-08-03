<?php

declare(strict_types=1);

namespace App\Modules\Crm\Listeners;

use App\Events\NotificationCreated;
use App\Models\User;
use App\Modules\Crm\Resources\NotificationResource;
use App\Support\SafeBroadcast;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;

/**
 * Pushes a CRM in-app notification to its recipient the moment it is stored.
 *
 * ONE PATH, NOT A SECOND ONE. This hangs off Laravel's own notification lifecycle
 * rather than sitting beside it: a producer calls `$user->notify(...)`, the database
 * channel persists the row, Laravel fires NotificationSent carrying that row, and
 * this broadcasts it. Nothing about how a notification is created changes, no
 * producer is edited, and — the reason for doing it this way — no future producer
 * can forget to broadcast. A service wrapper would have given the same result today
 * while depending on every future author remembering to call it; there is no way to
 * create a CRM database notification that bypasses this.
 *
 *   producer → notify() → DatabaseChannel persists → NotificationSent → here → Reverb
 *
 * Deliberately NOT ShouldQueue. The notification itself may be queued (several CRM
 * producers are), in which case this already runs in the worker; making the listener
 * queue separately would add a second hop between persisting and pushing for no gain.
 */
class BroadcastCrmNotification
{
    public function handle(NotificationSent $event): void
    {
        // The database channel is the in-app feed. Mail, and any future channel,
        // deliver themselves — InboundReplyMailNotification would otherwise be
        // announced to the bell as though it were an in-app notification.
        if ($event->channel !== 'database') {
            return;
        }

        // Only CRM notifications ride the CRM's module tag. Nothing else writes to
        // this table today, but that is a fact about now, not a guarantee: HR derives
        // its notifications live and Projects has its own table, and either could
        // change without this listener being reconsidered.
        if (! str_starts_with($event->notification::class, 'App\\Modules\\Crm\\')) {
            return;
        }

        // `user.{id}` addresses a user. A notifiable that is not one has no channel
        // to receive on.
        if (! $event->notifiable instanceof User) {
            return;
        }

        // For the database channel this is the persisted row. Guarded rather than
        // assumed: a custom channel could return anything.
        if (! $event->response instanceof DatabaseNotification) {
            return;
        }

        $payload = (new NotificationResource($event->response))->resolve(request());
        $recipientId = (int) $event->notifiable->getKey();

        // Broadcast only once the surrounding transaction commits, so a rolled-back
        // notification is never announced to a client that would then fail to find
        // it. Runs immediately when no transaction is open. Same guard the Projects
        // notification path uses.
        DB::afterCommit(fn () => SafeBroadcast::dispatch(
            new NotificationCreated($recipientId, 'crm', $payload)
        ));
    }
}
