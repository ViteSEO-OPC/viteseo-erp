<?php

namespace App\Modules\Crm\Services\Outreach;

use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\EmailEvent;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * RC3 Phase 1 — Native Scheduled Outbound (Send Later). Owns the pre-execution lifecycle of an
 * outbound email (schedule → reschedule/cancel/send-now) and its execution, but delegates the actual
 * transport to the SINGLE outbound pipeline {@see OutreachEmailService::sendExisting()} (RC3 FROZEN #1).
 *
 * Immutability: only a `scheduled` email may be modified; once it has been sent it is immutable history
 * on the existing `emails` table. Every transition is recorded on the append-only `email_events` timeline.
 * Content is user-authored — no AI, no templates in Phase 1.
 */
class ScheduledEmailService
{
    /** States on which Cancel / Reschedule / Send-Now are permitted (before execution). */
    private const PENDING = 'scheduled';

    public function __construct(private readonly OutreachEmailService $outreach) {}

    /**
     * Persist a Send-Later email (status=scheduled). Does NOT send — the scheduler job dispatches it
     * when due. `scheduled_at` is validated as future at the request layer.
     */
    public function schedule(Lead $lead, User $sender, string $subject, string $body, Carbon $scheduledAt, ?string $to = null): Email
    {
        $email = Email::create([
            'lead_id' => $lead->id,
            'user_id' => $sender->id,
            'direction' => 'outbound',
            'to_email' => $to !== null && $to !== '' ? $to : (string) $lead->email,
            'to_name' => $lead->contact_name,
            'subject' => $subject,
            'body' => $body,
            'status' => 'scheduled',
            'scheduled_at' => $scheduledAt,
        ]);

        $this->record($email, 'scheduled', ['scheduled_at' => $scheduledAt->toIso8601String()]);

        return $email;
    }

    /**
     * Schedule an email whose schedule is owned by a Planner "Email Task" event (RC3 Planner ↔ Outreach
     * link). The email carries NO independent `scheduled_at` — its execution schedule DERIVES from the
     * linked event's `starts_at` (Planner is the scheduling authority). Outreach still owns the content
     * and the send. Reschedule the *event* to move the send; there is never a second editable value.
     */
    public function scheduleForEvent(PlannerEvent $event, User $sender, string $subject, string $body, ?string $to = null): Email
    {
        // Recipient (and to_name) are resolved from the lead at SEND time by the single pipeline
        // (OutreachEmailService::sendExisting), so nothing here needs to dereference the optional lead.
        $email = Email::create([
            'lead_id' => $event->lead_id,
            'planner_event_id' => $event->id,
            'user_id' => $sender->id,
            'direction' => 'outbound',
            'to_email' => $to ?? '',
            'subject' => $subject,
            'body' => $body,
            'status' => 'scheduled',
            'scheduled_at' => null, // authoritative schedule = linked event's starts_at
        ]);

        $this->record($email, 'scheduled', ['planner_event_id' => $event->id, 'schedule_source' => 'planner_event']);

        return $email;
    }

    /**
     * Move a still-scheduled STANDALONE email to a new future time. A Planner-linked email is rejected:
     * its schedule lives on the Planner event (single authority) — reschedule the event instead.
     */
    public function reschedule(Email $email, Carbon $scheduledAt): Email
    {
        $this->assertPending($email);

        if ($email->planner_event_id !== null) {
            throw ValidationException::withMessages([
                'email' => 'This email\'s schedule is managed by its linked Planner event; reschedule the event.',
            ]);
        }

        $email->update(['scheduled_at' => $scheduledAt]);
        $this->record($email, 'rescheduled', ['scheduled_at' => $scheduledAt->toIso8601String()]);

        return $email;
    }

    /** Cancel a still-scheduled email (soft — status=cancelled; the row stays as history). */
    public function cancel(Email $email): Email
    {
        $this->assertPending($email);

        $email->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $this->record($email, 'cancelled');

        return $email;
    }

    /** Send a still-scheduled email immediately, bypassing the timer. */
    public function sendNow(Email $email, User $sender): Email
    {
        $this->assertPending($email);

        return $this->deliver($email);
    }

    /**
     * Execute a due (or Send-Now) scheduled email through the single outbound pipeline. Idempotent:
     * only a `scheduled`/`queued` row is ever sent, so overlapping scheduler runs / retries cannot
     * double-send. On provider failure the row becomes `failed` (terminal for Phase 1).
     */
    public function deliver(Email $email): Email
    {
        if (! in_array($email->status, [self::PENDING, 'queued'], true)) {
            return $email; // already sent / cancelled / failed — nothing to do
        }

        $email->update(['status' => 'sending', 'queued_at' => $email->queued_at ?? now()]);
        $this->record($email, 'sending');

        $sender = $email->sender;
        if ($sender === null) {
            return $this->markFailed($email, 'The scheduling user no longer exists.');
        }

        try {
            $sent = $this->outreach->sendExisting($email, $sender); // → status=sent + email.sent Activity
            $this->record($sent, 'sent', ['provider' => $sent->provider]);

            return $sent;
        } catch (\Throwable $e) {
            return $this->markFailed($email, $e->getMessage());
        }
    }

    private function markFailed(Email $email, string $error): Email
    {
        $email->update(['status' => 'failed', 'failed_at' => now(), 'error' => mb_substr($error, 0, 1000)]);
        $this->record($email, 'failed', ['error' => mb_substr($error, 0, 255)]);
        Log::warning('outreach.scheduled.failed', ['email_id' => $email->id, 'error' => $error]);

        return $email;
    }

    private function assertPending(Email $email): void
    {
        if ($email->status !== self::PENDING) {
            throw ValidationException::withMessages([
                'email' => 'Only a scheduled email can be changed; once sent it is immutable history.',
            ]);
        }
    }

    /**
     * Atomically claim a due email (scheduled → queued) so exactly one worker sends it. Returns the
     * refreshed row when this caller won the claim, or null if another run already took it.
     */
    public function claim(Email $email): ?Email
    {
        $won = Email::query()
            ->whereKey($email->id)
            ->where('status', self::PENDING)
            ->update(['status' => 'queued', 'queued_at' => now(), 'attempts' => DB::raw('attempts + 1')]);

        if ($won === 0) {
            return null;
        }

        $email->refresh();
        $this->record($email, 'queued');

        return $email;
    }

    /** @param array<string, mixed> $meta */
    private function record(Email $email, string $type, array $meta = []): void
    {
        EmailEvent::create([
            'email_id' => $email->id,
            'type' => $type,
            'occurred_at' => now(),
            'meta' => $meta === [] ? null : $meta,
        ]);
    }
}
