<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SubscriptionReminder;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Emails a customer's Finance Admin when their trial/subscription is about to
 * lapse (3 days and 1 day out) or has just lapsed. Idempotent per day via fixed
 * day thresholds. With MAIL_MAILER=log these write to the log until SMTP is set.
 */
class SendSubscriptionReminders extends Command
{
    protected $signature = 'accounting:subscription-reminders {--as-of= : Evaluate as of this date (Y-m-d), default today}';

    protected $description = 'Email subscription expiry reminders to customers';

    private const WARN_DAYS = [3, 1]; // days-before-lapse to warn

    public function handle(SubscriptionService $subscriptions): int
    {
        $today = ($this->option('as-of') ? Carbon::parse($this->option('as-of')) : Carbon::now())->startOfDay();

        $sent = 0;
        foreach (Subscription::query()->whereNotNull('current_period_end')->get() as $sub) {
            $subscriptions->refreshStatus($sub); // lazy-suspend a lapsed one first
            $sub->refresh();

            $endDate = $sub->current_period_end->copy()->startOfDay();
            $daysLeft = (int) $today->diffInDays($endDate, false); // + future, − past, 0 today

            $kind = $this->kindFor($sub->status, $daysLeft);
            if ($kind === null) {
                continue;
            }

            $admin = $this->adminFor($sub->org_id);
            $org = DB::table('orgs')->where('id', $sub->org_id)->value('name') ?? 'your organization';
            if (! $admin) {
                $this->warn("Org #{$sub->org_id}: no admin to notify — skipped.");

                continue;
            }

            $admin->notify(new SubscriptionReminder($kind, max(0, $daysLeft), $sub->plan, (float) $sub->monthly_price, (string) $org));
            $sent++;
            $this->line("Org #{$sub->org_id} ({$org}): {$kind} reminder → {$admin->email}");
        }

        $this->info("Done. {$sent} reminder(s) sent (mailer: " . config('mail.default') . ').');

        return self::SUCCESS;
    }

    /** Which reminder (if any) this subscription warrants today. */
    private function kindFor(string $status, int $daysLeft): ?string
    {
        if (in_array($status, [Subscription::TRIALING, Subscription::ACTIVE], true) && in_array($daysLeft, self::WARN_DAYS, true)) {
            return SubscriptionReminder::EXPIRING;
        }
        // Just lapsed (ended today or yesterday) and now suspended.
        if ($status === Subscription::SUSPENDED && in_array($daysLeft, [0, -1], true)) {
            return SubscriptionReminder::EXPIRED;
        }

        return null;
    }

    /** The org's Finance Admin, else any member. */
    private function adminFor(int $orgId): ?User
    {
        $memberIds = DB::table('org_user')->where('org_id', $orgId)->pluck('user_id');
        if ($memberIds->isEmpty()) {
            return null;
        }

        return User::query()->whereIn('id', $memberIds)->whereHas('roles', fn ($q) => $q->where('name', 'acc_admin'))->first()
            ?? User::query()->whereIn('id', $memberIds)->first();
    }
}
