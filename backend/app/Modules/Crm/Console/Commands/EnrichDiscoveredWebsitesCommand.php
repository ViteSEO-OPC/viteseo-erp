<?php

namespace App\Modules\Crm\Console\Commands;

use App\Models\Org;
use App\Models\Subscription;
use App\Modules\Crm\Services\Discovery\NerbyServiceIdentity;
use App\Modules\Crm\Services\Discovery\ScraperProcessRunner;
use App\Services\SubscriptionService;
use App\Support\Entitlements;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * THE LIFECYCLE THAT WAS MISSING. Runs the existing Website Intelligence pipelines on a schedule, so
 * a discovered lead stops depending on somebody remembering to run a command.
 *
 * WHY THIS EXISTS. Both pipelines were built, both worked, and neither was ever scheduled — the
 * deployment runbook says so outright ("Nearby Clients has no scheduled job today"). The consequence
 * was measurable: every Google-discovered lead in this database sat at `website-unknown`, shown as
 * "Not audited", because nothing had ever asked. That is not a gap in the pipeline; it is a missing
 * trigger, and this command is the trigger.
 *
 * IT IMPLEMENTS NOTHING ITSELF. Which leads to process, how to judge a page, when a URL belongs to a
 * business, and what `website_status` may become are all decided where they already were — the
 * engine's two queues, {@see \App\Modules\Crm\Services\WebsiteIdentityVerifier},
 * {@see \App\Modules\Crm\Services\Website\WebsiteEvidenceService} and the untouched
 * {@see \App\Modules\Crm\Services\WebsiteStatusDeriver}. This command starts them and reports how
 * they ended. It cannot promote a lead, cannot write a status, and cannot invent a classification.
 *
 * TWO QUEUES, ONE TICK, BECAUSE THEY ARE COMPLEMENTARY. `audit` takes leads that HAVE a URL;
 * `find-websites` takes leads that have none. Between them they cover every discovered lead, and
 * running both here means one schedule entry rather than two that could drift apart.
 *
 * BOUNDED BY CONSTRUCTION. Each step takes an explicit `--limit`, so a tick does a fixed, small
 * amount of work however large the backlog grows — the queues are ordered never-checked-first, so
 * successive ticks drain the backlog without ever re-treading finished leads.
 *
 *   php artisan crm:enrich-websites                     what the scheduler runs
 *   php artisan crm:enrich-websites --audit=5 --search=0 a small manual slice, e.g. to observe one
 */
class EnrichDiscoveredWebsitesCommand extends Command
{
    protected $signature = 'crm:enrich-websites
        {--audit= : How many leads WITH a URL to audit this tick (default: config)}
        {--search= : How many leads WITHOUT a URL to search for one (default: config)}';

    protected $description = 'Run the existing website audit + evidence pipelines over a bounded slice of their queues';

    /**
     * How long one tick may hold the lock. Longer than any tick the configured limits permit
     * (measured worst case is ~21 minutes) and far shorter than a day, so a process killed mid-run —
     * a deploy, an OOM — costs at most one skipped tick rather than silencing enrichment until
     * somebody notices.
     */
    private const LOCK_SECONDS = 2700;

    public function handle(
        ScraperProcessRunner $runner,
        NerbyServiceIdentity $service,
        SubscriptionService $subscriptions,
    ): int {
        // THE KILL SWITCH COMES FIRST — before the lock, before the service identity, before any
        // org is read. The schedule entry checks it too, but the command is also started by name
        // (the docker `enricher` loop, cron, an operator), so the command must refuse on its own.
        // Disabled is a normal state, not a failure: exit 0 so nothing watching treats it as one.
        // A one-off manual run sets the variable for that invocation only:
        //   DISCOVERY_ENRICHMENT_ENABLED=true php artisan crm:enrich-websites --audit=5 --search=0
        if (! config('discovery.enrichment.enabled')) {
            $this->info('enrich-websites: disabled (DISCOVERY_ENRICHMENT_ENABLED is not true); nothing was run.');

            return self::SUCCESS;
        }

        // THE GUARD TRAVELS WITH THE COMMAND, not with one caller.
        //
        // `withoutOverlapping()` protects the SCHEDULE ENTRY, so it protects exactly one trigger. It
        // cannot protect this stack's actual trigger: `schedule:run` fires every DUE entry, including
        // the outreach ones, and this database holds emails scheduled for 2026-07-19 with no age cap
        // — so running Laravel's scheduler to get enrichment would also mail two-month-old drafts.
        // The trigger therefore has to invoke this command directly, and a direct invocation gets no
        // overlap protection from the scheduler at all.
        //
        // An atomic cache lock here gives every caller the same guarantee: the scheduler, a cron
        // entry, an interval loop, and an operator typing it by hand are all mutually exclusive. The
        // pipeline underneath is idempotent anyway — enrichment is a 1:1 updateOrCreate, candidates
        // carry a (lead, source, url_hash) unique index, a search row is unique per lead — so an
        // overlap could not corrupt anything; it would double real outbound traffic to other people's
        // servers, which is reason enough.
        //
        // SKIPPED SILENTLY AND SUCCESSFULLY, matching withoutOverlapping(): the queues are ordered
        // never-checked-first, so the tick already running covers this one's work and the next tick
        // resumes where it stopped. A non-zero exit here would make an ordinary overlap look like a
        // failure to whatever is watching.
        $lock = Cache::lock('crm:enrich-websites', self::LOCK_SECONDS);

        if (! $lock->get()) {
            $this->info('enrich-websites: a tick is already running; skipping this one.');

            return self::SUCCESS;
        }

        try {
            return $this->tickEachOrg($runner, $service, $subscriptions);
        } finally {
            $lock->release();
        }
    }

    /**
     * ONE PASS PER TENANT, EACH WITH ITS OWN ORG-BOUND TOKEN.
     *
     * The engine reads its queue from, and writes its findings to, whatever org its token acts for.
     * With the old global identity that was always the provider org, so every other tenant's leads
     * were never enriched and the provider org was the only one ever written. Now each org that has
     * CRM and a live subscription gets its own short-lived token, revoked when its pass ends, and the
     * limits apply per org.
     */
    private function tickEachOrg(
        ScraperProcessRunner $runner,
        NerbyServiceIdentity $service,
        SubscriptionService $subscriptions,
    ): int {
        $failed = false;

        foreach (Org::query()->orderBy('id')->pluck('id') as $orgId) {
            $orgId = (int) $orgId;
            if (! Entitlements::has($orgId, 'crm')) {
                continue;
            }
            $subscription = Subscription::query()->where('org_id', $orgId)->first();
            if ($subscription === null || ! $subscriptions->hasAccess($subscription)) {
                continue;
            }

            $issued = $service->issue(
                $orgId,
                'enrichment',
                now()->addSeconds(max(60, (int) config('discovery.enrichment_timeout', 900) * 2 + 60)),
            );

            try {
                $failed = $this->tick($runner, $issued->plainTextToken, $orgId) === self::FAILURE || $failed;
            } finally {
                $service->revoke($issued);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function tick(ScraperProcessRunner $runner, string $crmToken, int $orgId): int
    {
        $auditLimit = (int) ($this->option('audit') ?? config('discovery.enrichment.audit_limit', 25));
        $searchLimit = (int) ($this->option('search') ?? config('discovery.enrichment.search_limit', 25));

        $failed = false;

        if ($auditLimit > 0) {
            $result = $runner->audit($auditLimit, $crmToken);
            $this->report("audit (org {$orgId})", $auditLimit, $result);
            $failed = $failed || $result['exitCode'] !== 0;
        }

        if ($searchLimit > 0) {
            $result = $runner->findWebsites($searchLimit, $crmToken);
            $this->report("find-websites (org {$orgId})", $searchLimit, $result);
            $failed = $failed || $result['exitCode'] !== 0;
        }

        // A NON-ZERO EXIT IS REPORTED, NOT RETRIED. These queues are ordered so the next scheduled
        // tick picks up exactly where this one stopped; retrying here would only re-crawl the same
        // sites sooner. An operator sees the failure in the schedule log and the work is not lost.
        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @param array{exitCode: int, stderrTail: string} $result */
    private function report(string $step, int $limit, array $result): void
    {
        if ($result['exitCode'] === 0) {
            $this->info("{$step}: ran over up to {$limit} lead(s).");

            return;
        }

        // The tail is already bounded and carries no credential (see ScraperProcessRunner).
        $this->warn("{$step}: exited {$result['exitCode']}. ".$result['stderrTail']);
    }
}
