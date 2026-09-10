<?php

namespace App\Modules\Crm\Database\Seeders;

use App\Models\User;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use App\Modules\Crm\Models\ServiceArea;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * CRM (Zerem) demo data — exercises Dashboard, Leads, Planner, Nearby Clients, Outreach and Reports
 * with a realistic pipeline instead of empty screens.
 *
 * Scope: CRM-owned tables only (crm_*) plus the CRM demo logins on the shared `users` table — leads
 * cannot be assigned without a BD, and no other seeder grants a CRM role, so a CRM login has to come
 * from here. HR/PMS seeders and their data are never touched.
 *
 * Determinism: the Faker generator is seeded, so every run produces the same businesses, amounts and
 * dates. Idempotency: the seeder no-ops when CRM leads already exist, so `db:seed` can be re-run
 * safely without duplicating the pipeline. Users use firstOrCreate and are safe regardless.
 *
 * Note on the two lead populations (see Lead::scopeProspect / scopeActive):
 *  - Prospect Pool (Nearby Clients) = `source` set + `qualified_at` NULL. Unassigned, excluded from
 *    all Business Computation.
 *  - Active CRM Leads (Dashboard/Reports/KPIs) = `source` NULL (manually created) OR `qualified_at`
 *    set (discovered, then qualified in).
 * Both are seeded so each surface has the data it actually queries.
 */
class CrmDemoSeeder extends Seeder
{
    /** Fixed clock so seeded dates land in stable buckets relative to "now". */
    private Carbon $now;

    /** Target areas the Nearby Clients "in target location" meta counts against. */
    private const AREAS = [
        ['name' => 'Makati City', 'level' => 'city'],
        ['name' => 'Quezon City', 'level' => 'city'],
        ['name' => 'Cavite', 'level' => 'province'],
    ];

    /** Metro Manila-ish coordinates for the Nearby Clients map, per city. */
    private const CITIES = [
        'Makati City' => [14.5547, 121.0244, 'Metro Manila', 'Metro Manila'],
        'Quezon City' => [14.6760, 121.0437, 'Metro Manila', 'Metro Manila'],
        'Taguig City' => [14.5176, 121.0509, 'Metro Manila', 'Metro Manila'],
        'Bacoor' => [14.4590, 120.9390, 'Region IV-A', 'Cavite'],
        'Cebu City' => [10.3157, 123.8854, 'Region VII', 'Cebu'],
    ];

    public function run(): void
    {
        // Demo data (incl. `password`-password logins) must never reach a real
        // environment — same guard idea as the HR demo seeders.
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('  CrmDemoSeeder refused: demo data only seeds in local/testing.');

            return;
        }

        // Same output on every run — no drifting demo data between machines.
        fake()->seed(20260717);
        $this->now = Carbon::create(2026, 7, 17, 9, 0, 0);

        $users = $this->users();

        // Idempotency: the pipeline is seeded once. Re-running `db:seed` must not duplicate it.
        // (Users above are firstOrCreate, so the CRM logins are repaired even on a skipped run.)
        if (Lead::withTrashed()->exists()) {
            $this->command?->warn('  CRM demo data already present — skipping (idempotent).');

            return;
        }

        $this->serviceAreas();

        $bds = $users['bds'];
        $admin = $users['admin'];

        $leads = $this->activeLeads($bds, $admin);
        $this->prospects($admin);
        $this->deals($leads, $admin);
        $this->plannerEvents($leads, $bds, $admin);
        $this->emails($leads, $bds);

        $this->command?->info(sprintf(
            '  CRM demo: %d active leads, %d prospects, %d deals, %d planner events, %d emails, %d areas.',
            Lead::active()->count(),
            Lead::prospect()->count(),
            Deal::count(),
            PlannerEvent::count(),
            Email::count(),
            ServiceArea::count(),
        ));
    }

    /**
     * CRM demo logins. No other seeder assigns a CRM role, so without these the whole CRM 403s.
     * These are CRM users only — deliberately no hr_employees record (that is HR's concern).
     *
     * @return array{admin: User, bds: list<User>}
     */
    private function users(): array
    {
        $make = function (string $email, string $name, string $role): User {
            $user = User::firstOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => Hash::make('password')],
            );

            // Additive: never strip roles the platform may have granted this account elsewhere.
            if (! $user->hasRole($role)) {
                $user->assignRole($role);
            }

            return $user;
        };

        return [
            'admin' => $make('crm.admin@viteseo.ph', 'Rea Villanueva', 'crm_admin'),
            'bds' => [
                $make('crm.bd@viteseo.ph', 'Jhamire Aranda', 'crm_bd'),
                $make('crm.bd2@viteseo.ph', 'Paolo Mercado', 'crm_bd'),
                $make('crm.bd3@viteseo.ph', 'Divine Lacsamana', 'crm_bd'),
            ],
        ];
    }

    /** Target service areas — drives Nearby Clients' `leadsInTargetLocation` meta. */
    private function serviceAreas(): void
    {
        foreach (self::AREAS as $area) {
            ServiceArea::updateOrCreate(
                ['name' => $area['name'], 'level' => $area['level']],
                ['active' => true],
            );
        }
    }

    /**
     * 40 Active CRM Leads — the pipeline every dashboard/report/KPI queries.
     *
     * Covers all five statuses, all three priorities, all website conditions, all outreach and
     * cold-calling states, assigned + unassigned, and spreads created_at across six months so the
     * Lead Acquisition chart and the rolling KPI windows have real shape.
     *
     * @param  list<User>  $bds
     * @return list<Lead>
     */
    private function activeLeads(array $bds, User $admin): array
    {
        // status => count (40 total). Weighted like a real funnel: more early-stage than declined.
        $mix = [
            'new' => 8,
            'interested' => 10,
            'awaiting-reply' => 8,
            'call-back-req' => 7,
            'declined' => 7,
        ];

        $websiteStatuses = ['no-website', 'needs-redesign', 'has-website'];
        $outreach = ['none', 'sent', 'opened', 'replied'];
        $calling = ['not-called', 'called', 'scheduled'];
        $priorities = ['high', 'medium', 'low'];
        $cities = array_keys(self::CITIES);

        $leads = [];
        $i = 0;

        foreach ($mix as $status => $count) {
            for ($n = 0; $n < $count; $n++, $i++) {
                // Spread across the last 6 months so the acquisition chart is populated month by month.
                $createdAt = $this->now->copy()
                    ->subDays((int) round($i * (175 / 40)))
                    ->setTime(fake()->numberBetween(8, 18), fake()->numberBetween(0, 59));

                // 6 of 40 unassigned — an Admin still has them to distribute.
                $assignee = $i % 7 === 6 ? null : $bds[$i % count($bds)];

                $city = $cities[$i % count($cities)];
                [$lat, $lng, $region, $province] = self::CITIES[$city];

                // A quarter of the pipeline arrived via discovery and was qualified in; the rest were
                // created by hand. Both are Active (scopeActive), which is what dashboards count.
                $wasDiscovered = $i % 4 === 0;

                $leads[] = Lead::factory()->create([
                    'status' => $status,
                    'priority' => $priorities[$i % 3],
                    'website_status' => $websiteStatuses[$i % 3],
                    'email_outreach' => $status === 'new' ? 'none' : $outreach[$i % 4],
                    'cold_calling' => $calling[$i % 3],
                    'score' => $this->scoreFor($status, $i),
                    'amount' => in_array($status, ['interested', 'awaiting-reply', 'call-back-req'], true)
                        ? fake()->numberBetween(15, 120) * 1000
                        : null,
                    'city' => $city,
                    'region' => $region,
                    'province' => $province,
                    'address' => fake()->streetAddress(),
                    'latitude' => $lat + (fake()->numberBetween(-250, 250) / 10000),
                    'longitude' => $lng + (fake()->numberBetween(-250, 250) / 10000),
                    'website' => $websiteStatuses[$i % 3] === 'no-website' ? null : 'https://'.fake()->domainName(),
                    'source' => $wasDiscovered ? 'nearby' : null,
                    'qualified_at' => $wasDiscovered ? $createdAt->copy()->addDays(2) : null,
                    'qualified_by' => $wasDiscovered ? ($assignee?->id ?? $admin->id) : null,
                    'assigned_to' => $assignee?->id,
                    'created_by' => $admin->id,
                    'last_activity' => $createdAt->copy()->addDays(fake()->numberBetween(0, 20)),
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);

                $this->activity($leads[$i], 'lead.created', 'Lead created', $admin, $createdAt);
            }
        }

        return $leads;
    }

    /**
     * 14 discovered businesses in the Prospect Pool (Nearby Clients): `source` set, never qualified,
     * unassigned, geocoded for the map. These take no part in Business Computation.
     *
     * Every prospect's website state is INTERNALLY CONSISTENT with how it could actually have been
     * reached, because the state now carries a claim about evidence:
     *
     *   - most are `website-unknown` with NO audit row — discovery only ever reports "not checked",
     *     so this is what a freshly discovered business genuinely looks like;
     *   - a few carry an audit row whose identity was VERIFIED, which is the only route to
     *     `has-website` / `needs-redesign`;
     *   - one was audited and did not respond (`website-unreachable`);
     *   - one was audited, responded, and could NOT be tied to the business — the case that keeps it
     *     at `website-unknown` despite having been checked.
     *
     * Seeding `no-website` here would assert "verified to have no website" about a business nothing
     * ever looked at, which is the exact defect this pipeline was corrected to stop making — and it
     * would hand the +25 greenfield signal to demo data. `no-website` is therefore absent by design;
     * it becomes reachable again only through candidate discovery (not built) or a human decision.
     */
    private function prospects(User $admin): void
    {
        $cities = array_keys(self::CITIES);

        // [website_status, has a URL, audit row to create (null = never audited)]
        $webStates = [
            ['website-unknown', false, null],
            ['website-unknown', false, null],
            ['website-unknown', true, null],
            ['website-unknown', false, null],
            ['website-unknown', true, null],
            ['website-unknown', false, null],
            ['website-unknown', true, null],
            ['website-unknown', false, null],
            ['website-unknown', true, null],
            ['has-website', true, ['reachable' => true, 'https' => true, 'core' => true, 'verified' => true]],
            ['has-website', true, ['reachable' => true, 'https' => true, 'core' => true, 'verified' => true]],
            ['needs-redesign', true, ['reachable' => true, 'https' => false, 'core' => false, 'verified' => true]],
            ['website-unreachable', true, ['reachable' => false, 'https' => false, 'core' => false, 'verified' => false]],
            ['website-unknown', true, ['reachable' => true, 'https' => true, 'core' => true, 'verified' => false]],
        ];

        for ($i = 0; $i < 14; $i++) {
            $city = $cities[$i % count($cities)];
            [$lat, $lng, $region, $province] = self::CITIES[$city];
            [$websiteStatus, $hasUrl, $audit] = $webStates[$i];
            $discoveredAt = $this->now->copy()->subDays(fake()->numberBetween(1, 45));

            $prospect = Lead::factory()->create([
                'status' => 'new',
                'priority' => ['high', 'medium', 'low'][$i % 3],
                'website_status' => $websiteStatus,
                'email_outreach' => 'none',
                'cold_calling' => 'not-called',
                'score' => 0,                 // prospects are unscored until qualified
                'amount' => null,
                'city' => $city,
                'region' => $region,
                'province' => $province,
                'address' => fake()->streetAddress(),
                'latitude' => $lat + (fake()->numberBetween(-350, 350) / 10000),
                'longitude' => $lng + (fake()->numberBetween(-350, 350) / 10000),
                'website' => $hasUrl ? 'https://'.fake()->domainName() : null,
                'place_id' => 'demo-place-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'source' => 'nearby',         // + qualified_at NULL below ⇒ scopeProspect
                'qualified_at' => null,
                'assigned_to' => null,        // the pool is shared, never owned
                'created_by' => $admin->id,
                'created_at' => $discoveredAt,
                'updated_at' => $discoveredAt,
            ]);

            if ($audit !== null) {
                $prospect->websiteEnrichment()->create([
                    'website_reachable' => $audit['reachable'],
                    'https_enabled' => $audit['https'],
                    'about_page' => $audit['core'],
                    'contact_page' => $audit['core'],
                    'services_page' => $audit['core'],
                    'final_url' => $audit['reachable'] ? $prospect->website : null,
                    'identity_verified' => $audit['verified'],
                    'identity_confidence' => $audit['verified'] ? 70 : 10,
                    'identity_signals' => [
                        'domain_matches_name' => $audit['verified'],
                        'title_matches_name' => $audit['verified'],
                        'phone_match' => false,
                        'email_domain_match' => false,
                        'city_match' => false,
                    ],
                    'provider' => 'scrapy-website-intelligence',
                    'last_checked_at' => $discoveredAt->copy()->addDays(1),
                    'enriched_at' => $discoveredAt->copy()->addDays(1),
                ]);
            }

            unset($prospect);
        }
    }

    /**
     * 8 Won deals (Dashboard "Converted Leads" + Reports revenue). A Deal IS the won signal — the
     * lead's status is never mutated by winning (mirrors LeadOutcomeController).
     *
     * @param  list<Lead>  $leads
     */
    private function deals(array $leads, User $admin): void
    {
        // Won business comes from engaged leads, not from `new`/`declined` ones.
        $winnable = array_values(array_filter(
            $leads,
            fn (Lead $l): bool => in_array($l->status, ['interested', 'awaiting-reply'], true),
        ));

        foreach (array_slice($winnable, 0, 8) as $i => $lead) {
            // Spread conversions over six months so revenue-by-month has shape.
            $convertedAt = $this->now->copy()->subDays((int) round($i * (170 / 8)));
            $amount = (float) ($lead->amount ?? fake()->numberBetween(20, 90) * 1000);

            Deal::factory()->create([
                'lead_id' => $lead->id,
                'amount' => $amount,
                'converted_at' => $convertedAt,
                'created_at' => $convertedAt,
                'updated_at' => $convertedAt,
            ]);

            $this->activity(
                $lead,
                'lead.won',
                sprintf('%s marked %s as won', $admin->name, $lead->business_name),
                $admin,
                $convertedAt,
                ['amount' => $amount],
            );
        }
    }

    /**
     * 25 Planner events across every type and status: past + upcoming, lead-linked, deal-linked and
     * standalone, including Nearby "Plan Visit" events (source: nearby) and undated Board backlog.
     *
     * @param  list<Lead>  $leads
     * @param  list<User>  $bds
     */
    private function plannerEvents(array $leads, array $bds, User $admin): void
    {
        $types = ['call', 'meeting', 'follow-up', 'email', 'task'];
        $statuses = ['todo', 'scheduled', 'in_progress', 'completed'];
        $owned = array_values(array_filter($leads, fn (Lead $l): bool => $l->assigned_to !== null));
        $deals = Deal::all();

        for ($i = 0; $i < 25; $i++) {
            $type = $types[$i % 5];
            $status = $statuses[$i % 4];

            // Completed events sit in the past; everything else is upcoming. 3 stay undated so the
            // Board backlog column is exercised.
            $undated = $i % 8 === 7;
            $startsAt = $undated ? null : ($status === 'completed'
                ? $this->now->copy()->subDays(fake()->numberBetween(1, 40))
                : $this->now->copy()->addDays(fake()->numberBetween(0, 21)));

            // Every 7th event is a Nearby "Plan Visit"; the rest are manual Planner work. The stride
            // must not be a multiple of the 5-type cycle above, or one type (task) would always be
            // overridden to a visit and never appear.
            $isVisit = $i % 7 === 6;
            $lead = $owned[$i % count($owned)];
            $deal = $i % 9 === 8 && $deals->isNotEmpty() ? $deals[$i % $deals->count()] : null;

            $event = PlannerEvent::factory()->create([
                'title' => $isVisit
                    ? 'Visit '.$lead->business_name
                    : ucfirst($type).' — '.$lead->business_name,
                'event_type' => $isVisit ? 'meeting' : $type,
                'status' => $status,
                'priority' => ['low', 'medium', 'high'][$i % 3],
                'starts_at' => $startsAt,
                'ends_at' => $startsAt?->copy()->addHour(),
                'all_day' => false,
                'reminder_minutes' => [null, 15, 30, 60][$i % 4],
                'source' => $isVisit ? 'nearby' : 'manual',
                'lead_id' => $deal ? null : $lead->id,
                'deal_id' => $deal?->id,
                'assigned_to' => $lead->assigned_to ?? $bds[$i % count($bds)]->id,
                'created_by' => $admin->id,
                'description' => fake()->sentence(),
                'created_at' => $startsAt?->copy()->subDays(2) ?? $this->now->copy()->subDays(5),
            ]);

            unset($event);
        }
    }

    /**
     * 30 Outreach emails — outbound across every delivery state (sent/draft/scheduled/queued/failed/
     * cancelled) plus inbound replies, threaded to their outbound parent so the Lead Details Email
     * Thread and the Delivery Monitor both have data.
     *
     * @param  list<Lead>  $leads
     * @param  list<User>  $bds
     */
    private function emails(array $leads, array $bds): void
    {
        // Only engaged leads have correspondence — a `new` lead with an email thread is not realistic.
        $engaged = array_values(array_filter(
            $leads,
            fn (Lead $l): bool => $l->assigned_to !== null && $l->status !== 'new',
        ));

        // 8 states — deliberately coprime with the 3 BDs. A 9-state cycle would correlate with the
        // 3-BD lead rotation (3 divides 9) and hand each BD a fixed subset: one BD would own every
        // draft, another every scheduled send. Coprime strides give each BD a realistic mix.
        $statuses = ['sent', 'sent', 'sent', 'draft', 'scheduled', 'queued', 'failed', 'cancelled'];
        $purposes = ['introduction', 'proposal', 'follow_up', 'thank_you'];

        /** @var array<int, User> $byId */
        $byId = collect($bds)->keyBy('id')->all();

        foreach ($engaged as $i => $lead) {
            // A lead whose outreach state is "replied" must have a delivered parent message —
            // you cannot receive a reply to a draft or a cancelled send. Forcing `sent` here keeps
            // leads.email_outreach consistent with the actual thread on Lead Details.
            $status = $lead->email_outreach === 'replied'
                ? 'sent'
                : $statuses[$i % count($statuses)];
            // The BD who owns the lead is the one who corresponded with it.
            $sender = $byId[$lead->assigned_to] ?? $bds[$i % count($bds)];
            $sentAt = $this->now->copy()->subDays(fake()->numberBetween(1, 90));
            $threadId = 'demo-thread-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT);
            $messageId = '<demo-'.$i.'@viteseo.ph>';

            // No EmailFactory exists in the module, so emails are created directly.
            $email = Email::create([
                'direction' => 'outbound',
                'lead_id' => $lead->id,
                'user_id' => $sender->id,
                'from_email' => 'outreach@viteseo.ph',
                'from_name' => $sender->name,
                'to_email' => $lead->email,
                'to_name' => $lead->contact_name,
                'subject' => $this->subjectFor($purposes[$i % 4], $lead),
                'body' => fake()->paragraph(3),
                'purpose' => $purposes[$i % 4],
                'provider' => in_array($status, ['draft', 'scheduled'], true) ? null : 'smtp',
                'message_id' => $messageId,
                'thread_id' => $threadId,
                'status' => $status,
                'sent_at' => $status === 'sent' ? $sentAt : null,
                'scheduled_at' => $status === 'scheduled' ? $this->now->copy()->addDays(2) : null,
                'queued_at' => $status === 'queued' ? $this->now->copy()->subMinutes(5) : null,
                'failed_at' => $status === 'failed' ? $sentAt : null,
                'cancelled_at' => $status === 'cancelled' ? $sentAt : null,
                'error' => $status === 'failed' ? 'SMTP 550: mailbox unavailable' : null,
                'attempts' => $status === 'failed' ? 3 : ($status === 'sent' ? 1 : 0),
                'created_at' => $sentAt,
                'updated_at' => $sentAt,
            ]);

            if ($status === 'sent') {
                $this->activity(
                    $lead,
                    'email.sent',
                    sprintf('%s emailed %s', $sender->name, $lead->business_name),
                    $sender,
                    $sentAt,
                );
            }

            // Leads that replied get a real inbound reply threaded to the outbound message.
            if ($status === 'sent' && $lead->email_outreach === 'replied') {
                $receivedAt = $sentAt->copy()->addDays(1);

                Email::create([
                    'direction' => 'inbound',
                    'lead_id' => $lead->id,
                    'user_id' => null,
                    'from_email' => $lead->email,
                    'from_name' => $lead->contact_name,
                    'to_email' => 'outreach@viteseo.ph',
                    'to_name' => $sender->name,
                    'subject' => 'Re: '.$this->subjectFor($purposes[$i % 4], $lead),
                    'body' => fake()->paragraph(2),
                    'provider' => 'gmail',
                    'message_id' => '<demo-reply-'.$i.'@acme.ph>',
                    'in_reply_to' => $messageId,
                    'thread_id' => $threadId,
                    'status' => 'received',
                    'received_at' => $receivedAt,
                    'created_at' => $receivedAt,
                    'updated_at' => $receivedAt,
                ]);

                $this->activity(
                    $lead,
                    'email.received',
                    sprintf('%s replied', $lead->contact_name),
                    null,
                    $receivedAt,
                );
            }
        }
    }

    /**
     * Append an Activity row. Seeders run under WithoutModelEvents (DatabaseSeeder), so the CRM
     * observers never fire here — the audit trail has to be written explicitly or the Activity Logs
     * page and the Dashboard's Recent Activity feed stay empty.
     *
     * @param  array<string, mixed>  $properties
     */
    private function activity(
        Lead $lead,
        string $event,
        string $description,
        ?User $causer,
        Carbon $at,
        array $properties = [],
    ): void {
        Activity::create([
            'subject_type' => Lead::class,
            'subject_id' => $lead->id,
            'causer_id' => $causer?->id,
            'event' => $event,
            'description' => $description,
            'properties' => $properties,
            'created_at' => $at,
        ]);
    }

    /** Scores that track the funnel — engaged leads outrank new/declined ones. */
    private function scoreFor(string $status, int $i): int
    {
        return match ($status) {
            'interested' => 70 + ($i % 30),
            'awaiting-reply' => 55 + ($i % 25),
            'call-back-req' => 45 + ($i % 25),
            'declined' => 10 + ($i % 20),
            default => 25 + ($i % 25),
        };
    }

    private function subjectFor(string $purpose, Lead $lead): string
    {
        return match ($purpose) {
            'proposal' => 'Proposal for '.$lead->business_name,
            'follow_up' => 'Following up — '.$lead->business_name,
            'thank_you' => 'Thank you, '.$lead->contact_name,
            default => 'Quick question about '.$lead->business_name,
        };
    }
}
