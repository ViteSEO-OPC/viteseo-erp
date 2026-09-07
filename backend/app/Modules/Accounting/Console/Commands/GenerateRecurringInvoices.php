<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Console\Commands;

use App\Models\Subscription;
use App\Models\User;
use App\Modules\Accounting\Services\RecurringInvoiceService;
use App\Modules\Accounting\Support\AccountingClock;
use App\Services\SubscriptionService;
use App\Support\CurrentOrg;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Generates every recurring invoice due today, across all orgs. Intended for a
 * daily schedule; also safe to run by hand. Each org's invoices are posted by
 * that org's Finance Admin (falling back to any member) so journal entries have
 * a real actor. Idempotent per day — a schedule advances past today once run.
 */
class GenerateRecurringInvoices extends Command
{
    protected $signature = 'accounting:generate-recurring {--as-of= : Generate as of this date (Y-m-d), default today}';

    protected $description = 'Generate all due recurring invoices across every org';

    public function handle(RecurringInvoiceService $service, SubscriptionService $subscriptions): int
    {
        $asOf = $this->option('as-of') ?: AccountingClock::today();

        $orgIds = DB::table('acc_recurring_invoices')
            ->where('status', 'active')
            ->whereDate('next_run_date', '<=', $asOf)
            ->distinct()
            ->pluck('org_id');

        if ($orgIds->isEmpty()) {
            $this->info('No recurring invoices are due.');

            return self::SUCCESS;
        }

        $total = 0;
        foreach ($orgIds as $orgId) {
            // Prepaid gate: a lapsed/suspended org keeps its schedules but must
            // not keep accruing invoices while locked out of the product.
            $subscription = Subscription::where('org_id', $orgId)->first();
            if (! $subscription || ! $subscriptions->hasAccess($subscription)) {
                $this->warn("Org #{$orgId}: subscription not active — skipped.");

                continue;
            }

            $actor = $this->actorFor((int) $orgId);
            if (! $actor) {
                $this->warn("Org #{$orgId}: no user to post as — skipped.");

                continue;
            }

            $count = CurrentOrg::runAs((int) $orgId, fn () => $service->generateDue($actor, $asOf));
            $total += $count;
            $this->line("Org #{$orgId}: generated {$count} invoice(s).");
        }

        $this->info("Done. {$total} invoice(s) generated.");

        return self::SUCCESS;
    }

    /** A user to post as: an org's Finance Admin, else any member. */
    private function actorFor(int $orgId): ?User
    {
        $memberIds = DB::table('org_user')->where('org_id', $orgId)->pluck('user_id');
        if ($memberIds->isEmpty()) {
            return null;
        }

        $admin = User::query()->whereIn('id', $memberIds)->whereHas('roles', fn ($q) => $q->where('name', 'acc_admin'))->first();

        return $admin ?? User::query()->whereIn('id', $memberIds)->first();
    }
}
