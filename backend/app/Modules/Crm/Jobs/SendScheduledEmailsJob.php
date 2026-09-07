<?php

namespace App\Modules\Crm\Jobs;

use App\Modules\Crm\Events\ScheduledEmailDue;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Services\Outreach\ScheduledEmailService;
use App\Support\CurrentOrg;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * RC3 Phase 1 — sends due Send-Later emails. Scheduled every minute (routes/console.php). For each due
 * row it performs an atomic claim (scheduled → queued) so overlapping runs never double-send, then
 * raises ScheduledEmailDue whose listener sends through the single outbound pipeline. Does not modify
 * the inbound pipeline. Queue = database.
 */
class SendScheduledEmailsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * A queued job has no authenticated user, so `CurrentOrg::id()` fell through to the
     * FIRST org and every other tenant's Send-Later mail sat `scheduled` forever. One
     * pinned pass per tenant instead.
     */
    public function handle(ScheduledEmailService $scheduled): void
    {
        CurrentOrg::eachOrg(fn (int $orgId) => $this->handleOrg($scheduled, $orgId));
    }

    private function handleOrg(ScheduledEmailService $scheduled, int $orgId): void
    {
        // Effective due-time is resolved per email: a STANDALONE email uses its own scheduled_at; a
        // PLANNER-LINKED email derives from the linked event's starts_at (Planner is the authority).
        $now = now();
        $due = Email::query()
            ->where('direction', 'outbound')
            ->where('status', 'scheduled')
            ->where(function ($q) use ($now) {
                $q->where(function ($standalone) use ($now) {
                    $standalone->whereNull('planner_event_id')
                        ->whereNotNull('scheduled_at')
                        ->where('scheduled_at', '<=', $now);
                })->orWhere(function ($linked) use ($now) {
                    $linked->whereNotNull('planner_event_id')
                        ->whereHas('plannerEvent', function ($event) use ($now) {
                            $event->whereNotNull('starts_at')->where('starts_at', '<=', $now);
                        });
                });
            })
            ->orderBy('id')
            ->limit(100)
            ->get();

        $dispatched = 0;
        foreach ($due as $email) {
            $claimed = $scheduled->claim($email);
            if ($claimed === null) {
                continue; // another run already took it
            }

            event(new ScheduledEmailDue($claimed));
            $dispatched++;
        }

        if ($dispatched > 0) {
            Log::info('outreach.scheduled.dispatched', ['org_id' => $orgId, 'count' => $dispatched]);
        }
    }
}
