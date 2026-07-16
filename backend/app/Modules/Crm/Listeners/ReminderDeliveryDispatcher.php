<?php

namespace App\Modules\Crm\Listeners;

use App\Modules\Crm\Events\PlannerReminderDue;
use App\Modules\Crm\Models\ReminderDelivery;
use App\Modules\Crm\Notifications\PlannerReminderNotification;
use Illuminate\Support\Facades\Log;

/**
 * RC3 Phase 3 — the reminder DELIVERY subsystem (decoupled from Planner). Subscribes to PlannerReminderDue
 * and delivers the reminder through a channel, recording an append-only `reminder_deliveries` row. RC3
 * ships the `database` channel (RC2 Database Notification to the event's owner). Additional channels
 * (email/sms/slack/teams/whatsapp/n8n) plug in here later — Planner never changes.
 *
 * Idempotent + race-safe: firstOrCreate on the unique (planner_event_id, channel) claims the delivery;
 * only the winner notifies, so overlapping scheduler runs never double-send.
 */
class ReminderDeliveryDispatcher
{
    private const CHANNEL = 'database';

    public function handle(PlannerReminderDue $event): void
    {
        $plannerEvent = $event->event;
        $owner = $plannerEvent->owner;

        if ($owner === null) {
            return; // no assignee → nobody to remind
        }

        $delivery = ReminderDelivery::query()->firstOrCreate(
            ['planner_event_id' => $plannerEvent->id, 'channel' => self::CHANNEL],
            ['delivered_at' => now(), 'meta' => ['notifiable_id' => $owner->id]],
        );

        // Another run already delivered this reminder → do nothing.
        if (! $delivery->wasRecentlyCreated) {
            return;
        }

        $owner->notify(new PlannerReminderNotification($plannerEvent));

        Log::info('planner.reminder.delivered', [
            'planner_event_id' => $plannerEvent->id,
            'channel' => self::CHANNEL,
            'notifiable_id' => $owner->id,
        ]);
    }
}
