<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Org;
use App\Models\User;
use App\Modules\Crm\Events\ScheduledEmailDue;
use App\Modules\Crm\Jobs\SendScheduledEmailsJob;
use App\Modules\Crm\Jobs\SyncMailboxJob;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\MailboxOauthToken;
use App\Modules\Crm\Models\MailboxSync;
use App\Modules\Crm\Services\Mail\Contracts\MailboxSyncProvider;
use App\Modules\Crm\Services\Mail\Inbound\MailboxFetchResult;
use App\Modules\Crm\Services\Mail\MailboxSyncManager;
use App\Modules\Crm\Services\Outreach\InboundEmailService;
use App\Modules\Crm\Services\Outreach\ScheduledEmailService;
use App\Services\OrgOnboardingService;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Nobody is signed in to a cron job.
 *
 * `CurrentOrg::id()` resolves from `auth()->user()`, and a queued job, a scheduled
 * command and an inbound webhook have none — so the resolution falls through to
 * `Org::orderBy('id')->value('id')`: the FIRST org. Every unattended per-tenant path
 * therefore did one tenant's work and reported success. That is worse than a crash
 * in both directions at once. Reads: `outreach:sync` polled one owner's mailbox and
 * logged "Inbound sync dispatched" every five minutes. Writes: `BelongsToOrg` stamps
 * `org_id` from the same value, so anything a job created landed in the provider's
 * tenant — one customer's inbound mail filed against another customer's leads.
 *
 * These tests all have the same shape, because the bug does: create the same work in
 * TWO orgs, run the unattended path once, and assert both tenants were served.
 */
class BackgroundWorkRunsForEveryTenantTest extends TestCase
{
    use RefreshDatabase;

    private function orgNamed(string $name): Org
    {
        return Org::create(['name' => $name, 'is_active' => true]);
    }

    /** A provider that returns nothing, so the job exercises resolution and cursoring only. */
    private function managerReturningNothing(): MailboxSyncManager
    {
        $provider = new class implements MailboxSyncProvider
        {
            public function fetchSince(string $mailbox, ?string $cursor): MailboxFetchResult
            {
                return new MailboxFetchResult([], 'cursor-1');
            }
        };

        return (new MailboxSyncManager($this->app))->extend('gmail', fn () => $provider);
    }

    public function test_the_inbound_fan_out_reaches_every_tenants_mailbox(): void
    {
        Queue::fake();

        $a = $this->orgNamed('Alpha');
        $b = $this->orgNamed('Beta');

        foreach ([[$a, 'alpha@company.test'], [$b, 'beta@company.test']] as [$org, $email]) {
            CurrentOrg::runAs($org->id, fn () => MailboxSync::create([
                'email' => $email, 'provider' => 'gmail', 'status' => 'active',
            ]));
        }

        // The scheduler runs this with no user. The fan-out read MailboxSync through
        // the global scope, so it could only ever see the first org's row: Beta's
        // mailbox was never polled and nothing said so.
        $this->artisan('outreach:sync')->assertSuccessful();

        Queue::assertPushed(SyncMailboxJob::class, 2);

        foreach ([[$a, 'alpha@company.test'], [$b, 'beta@company.test']] as [$org, $email]) {
            Queue::assertPushed(
                SyncMailboxJob::class,
                fn (SyncMailboxJob $job): bool => $job->mailbox === $email && $job->orgId === $org->id,
            );
        }
    }

    public function test_a_sync_runs_pinned_to_the_org_that_owns_the_mailbox(): void
    {
        $a = $this->orgNamed('Alpha');
        $b = $this->orgNamed('Beta');

        $theirs = CurrentOrg::runAs($b->id, fn () => MailboxSync::create([
            'email' => 'beta@company.test', 'provider' => 'gmail', 'status' => 'active',
        ]));

        // Run it the way the queue does: no authenticated user, no pin.
        (new SyncMailboxJob('beta@company.test', $b->id))
            ->handle($this->managerReturningNothing(), app(InboundEmailService::class));

        // Unpinned, the job's own firstOrCreate could not SEE Beta's row (the global
        // scope hid it) and created a SECOND one stamped with the first org — every
        // message the sync then ingested would have been written into Alpha.
        $this->assertSame(1, MailboxSync::withoutGlobalScope('org')->count());

        $theirs->refresh();
        $this->assertSame($b->id, (int) $theirs->org_id);
        $this->assertSame('cursor-1', $theirs->sync_cursor, "Beta's own cursor was not advanced.");
    }

    public function test_due_send_later_mail_is_dispatched_for_every_tenant(): void
    {
        Event::fake([ScheduledEmailDue::class]);

        $a = $this->orgNamed('Alpha');
        $b = $this->orgNamed('Beta');

        foreach ([$a, $b] as $org) {
            CurrentOrg::runAs($org->id, function () use ($org): void {
                $owner = User::create([
                    'name' => 'Owner '.$org->id,
                    'email' => "owner{$org->id}@client.test",
                    'password' => 'password-123',
                ]);

                $lead = Lead::create([
                    'business_name' => 'Lead '.$org->id,
                    'contact_name' => 'Contact',
                    'created_by' => $owner->id,
                ]);

                Email::create([
                    'lead_id' => $lead->id,
                    'user_id' => $owner->id,
                    'to_email' => "lead{$org->id}@nowhere.test",
                    'subject' => 'Scheduled',
                    'body' => 'body',
                    'provider' => 'smtp',
                    'direction' => 'outbound',
                    'status' => 'scheduled',
                    'scheduled_at' => now()->subMinute(),
                ]);
            });
        }

        // Scheduled every minute, so this ran ~1,440 times a day serving one tenant
        // while every other tenant's Send-Later mail sat `scheduled` forever.
        (new SendScheduledEmailsJob)->handle(app(ScheduledEmailService::class));

        Event::assertDispatched(ScheduledEmailDue::class, 2);

        foreach ([$a, $b] as $org) {
            Event::assertDispatched(
                ScheduledEmailDue::class,
                fn (ScheduledEmailDue $event): bool => (int) $event->email->org_id === $org->id,
            );
        }
    }

    public function test_the_unauthenticated_oauth_callback_stores_the_grant_in_the_consenting_org(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        config([
            'outreach.frontend_url' => '',
            'outreach.mailbox' => 'shared@company.test',
            'outreach.gmail.client_id' => 'client-id.apps.googleusercontent.com',
            'outreach.gmail.client_secret' => 'client-secret',
            'outreach.gmail.redirect_uri' => 'http://localhost:8001/auth/google/callback',
            'outreach.gmail.scopes' => ['https://www.googleapis.com/auth/gmail.readonly'],
        ]);

        // Alpha exists only to BE the first org — the tenant an unpinned write lands in.
        $this->orgNamed('Alpha');

        // Beta is a real customer: availed CRM Pro (which is what sells outreach) and
        // on a live trial, so it can actually reach the integration endpoint.
        $onboarded = app(OrgOnboardingService::class)->createOrg(
            'Beta', 'Beta Owner', 'beta-owner@client.ph', 'password-123', ['crm' => 'pro'],
        );
        $b = $onboarded['org'];

        Sanctum::actingAs($onboarded['admin']);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'granted-access',
                'refresh_token' => 'granted-refresh',
                'expires_in' => 3600,
                'scope' => 'https://www.googleapis.com/auth/gmail.readonly',
                'token_type' => 'Bearer',
            ]),
            'https://gmail.googleapis.com/gmail/v1/users/me/profile*' => Http::response([
                'emailAddress' => 'shared@company.test',
            ]),
        ]);

        // Beta's admin starts consent. This half IS authenticated.
        $url = $this->postJson('/api/v1/crm/settings/integrations/gmail/authorization')
            ->assertOk()->json('data.authorization_url');
        parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

        // Drop the acting-as identity. This is the whole point: Google redirects a BROWSER
        // to the CRM's only public endpoint — no bearer token, no cookie, no user — and that
        // endpoint writes a credential. Leaving the test signed in would quietly resolve the
        // org from the session and prove nothing.
        $this->app['auth']->forgetGuards();

        // ...and the pin the authenticated half left behind. In production Google's
        // redirect arrives on a fresh process with neither, so a test that kept either
        // would resolve the org from leftovers and pass whatever the code did.
        CurrentOrg::clear();

        $this->getJson('/auth/google/callback?code=auth-code&state='.$query['state'])
            ->assertOk()->assertJsonPath('data.status', 'connected');

        $token = MailboxOauthToken::withoutGlobalScope('org')->firstWhere('email', 'shared@company.test');

        $this->assertNotNull($token);

        // Unpinned, this landed on Alpha: the provider tenant quietly acquired another
        // company's Gmail refresh token, and Beta — whose reads ARE scoped — saw its own
        // connection simply never appear.
        $this->assertSame($b->id, (int) $token->org_id, "Beta's Gmail grant was stored in another tenant.");
    }
}
