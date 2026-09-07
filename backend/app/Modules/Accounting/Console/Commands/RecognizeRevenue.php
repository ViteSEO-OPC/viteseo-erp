<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Console\Commands;

use App\Models\User;
use App\Modules\Accounting\Services\RevenueRecognitionService;
use App\Support\CurrentOrg;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Recognizes every deferred-revenue month due today, across all orgs. Moves one
 * month at a time from Unearned Revenue into revenue. Idempotent per day — a
 * schedule advances past today once recognized. Meant for a daily schedule.
 */
class RecognizeRevenue extends Command
{
    protected $signature = 'accounting:recognize-revenue {--as-of= : Recognize as of this date (Y-m-d), default today}';

    protected $description = 'Recognize deferred revenue due today across every org';

    public function handle(RevenueRecognitionService $service): int
    {
        $asOf = $this->option('as-of') ?: Carbon::now()->toDateString();

        $orgIds = DB::table('acc_revenue_schedules')
            ->where('status', 'active')
            ->whereDate('next_recognition_date', '<=', $asOf)
            ->distinct()
            ->pluck('org_id');

        if ($orgIds->isEmpty()) {
            $this->info('No deferred revenue is due.');

            return self::SUCCESS;
        }

        $total = 0;
        foreach ($orgIds as $orgId) {
            $actor = $this->actorFor((int) $orgId);
            if (! $actor) {
                $this->warn("Org #{$orgId}: no user to post as — skipped.");

                continue;
            }

            $count = CurrentOrg::runAs((int) $orgId, fn () => $service->recognizeDue($actor, $asOf));
            $total += $count;
            $this->line("Org #{$orgId}: recognized {$count} month(s).");
        }

        $this->info("Done. {$total} monthly recognition(s) posted.");

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
