<?php

namespace App\Modules\Crm\Services\Outreach;

use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\EmailEvent;
use App\Modules\Crm\Models\PlannerEvent;
use App\Modules\Crm\Models\ReminderDelivery;
use App\Models\User;

/**
 * RC3 Phase 4 — read-only delivery-lifecycle monitoring. Aggregates the EXISTING append-only timelines
 * (`email_events` from Phase 1, `reminder_deliveries` from Phase 3) plus live `emails.status` — no new
 * persistence, no writes. SEND-LIFECYCLE ONLY (scheduled→queued→sending→sent|failed|cancelled + reminder
 * deliveries); deliberately NO engagement analytics (opens/clicks/bounces/spam/unsubscribe). No AI.
 *
 * Ownership-scoped: a BD sees their own outbound sends + reminders for events they own; Admin/SA see all.
 */
class DeliveryMonitorService
{
    /**
     * @return array{emails: array<string, mixed>, reminders: array<string, mixed>}
     */
    public function summary(User $viewer): array
    {
        $seesAll = $viewer->hasRole('crm_admin') || $viewer->hasRole('super_admin');

        return [
            'emails' => $this->emailSummary($viewer, $seesAll),
            'reminders' => $this->reminderSummary($viewer, $seesAll),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emailSummary(User $viewer, bool $seesAll): array
    {
        $scope = fn () => Email::query()
            ->where('direction', 'outbound')
            ->when(! $seesAll, fn ($q) => $q->where('user_id', $viewer->id));

        /** @var array<string, int> $statusCounts */
        $statusCounts = $scope()
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->map(fn ($c): int => (int) $c)
            ->all();

        $recentFailures = $scope()
            ->where('status', 'failed')
            ->latest('failed_at')
            ->limit(20)
            ->get(['id', 'subject', 'error', 'failed_at', 'lead_id'])
            ->map(fn (Email $e): array => [
                'id' => (string) $e->id,
                'subject' => $e->subject,
                'error' => $e->error,
                'failedAt' => $e->failed_at?->toIso8601String(),
                'leadId' => $e->lead_id !== null ? (string) $e->lead_id : null,
            ]);

        $recentEvents = EmailEvent::query()
            ->whereIn('email_id', $scope()->select('id'))
            ->latest('occurred_at')
            ->limit(30)
            ->get(['email_id', 'type', 'occurred_at'])
            ->map(fn (EmailEvent $e): array => [
                'emailId' => (string) $e->email_id,
                'type' => $e->type,
                'occurredAt' => $e->occurred_at?->toIso8601String(),
            ]);

        return [
            'statusCounts' => $statusCounts,
            'recentFailures' => $recentFailures,
            'recentEvents' => $recentEvents,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reminderSummary(User $viewer, bool $seesAll): array
    {
        $scope = fn () => ReminderDelivery::query()
            ->when(! $seesAll, fn ($q) => $q->whereIn(
                'planner_event_id',
                PlannerEvent::query()->where('assigned_to', $viewer->id)->select('id'),
            ));

        /** @var array<string, int> $channelCounts */
        $channelCounts = $scope()
            ->selectRaw('channel, count(*) as c')
            ->groupBy('channel')
            ->pluck('c', 'channel')
            ->map(fn ($c): int => (int) $c)
            ->all();

        $recentDeliveries = $scope()
            ->latest('delivered_at')
            ->limit(20)
            ->get(['planner_event_id', 'channel', 'delivered_at'])
            ->map(fn (ReminderDelivery $r): array => [
                'plannerEventId' => (string) $r->planner_event_id,
                'channel' => $r->channel,
                'deliveredAt' => $r->delivered_at?->toIso8601String(),
            ]);

        return [
            'channelCounts' => $channelCounts,
            'recentDeliveries' => $recentDeliveries,
        ];
    }
}
