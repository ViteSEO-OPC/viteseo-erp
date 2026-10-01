<?php

namespace Tests\Feature\Outreach;

use App\Models\User;
use App\Modules\Crm\Events\OutboundEmailSent;
use App\Modules\Crm\Events\ScheduledEmailDue;
use App\Modules\Crm\Jobs\SendScheduledEmailsJob;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\EmailEvent;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Mail\Contracts\EmailProvider;
use App\Modules\Crm\Services\Mail\EmailMessage;
use App\Modules\Crm\Services\Mail\SentEmail;
use App\Modules\Crm\Services\Outreach\ScheduledEmailService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * CEO audit crm-05 — a scheduled email is sent exactly once.
 *
 * Send Now used to decide from the row it loaded when the request began (policy + assertPending) and then
 * write `sending` unconditionally, while the automatic sender claimed the same row atomically. If the
 * scheduler claimed and delivered the email after Send Now had loaded it, both reached the provider: the
 * prospect received it twice, with two `email.sent` Activities and two OutboundEmailSent events. Two
 * overlapping Send Now requests did the same. Both paths now move the row to `sending` with a conditional
 * UPDATE, and only the winner sends.
 *
 * The transport is a counting fake — no real email is ever sent. Races are proven by committing the
 * competing send at the moment Send Now loads the email, before its own transition.
 */
class ScheduledEmailSendOnceTest extends TestCase
{
    use RefreshDatabase;

    private const NOT_PENDING = 'Only a scheduled email can be changed; once sent it is immutable history.';

    /** @var list<string> */
    public static array $providerCalls = [];

    /** 1-based provider call that should fail, or 0 for none. */
    public static int $failOnCall = 0;

    protected function setUp(): void
    {
        parent::setUp();
        self::$providerCalls = [];
        self::$failOnCall = 0;
        $this->seed(RolesAndPermissionsSeeder::class);
        Event::fake([OutboundEmailSent::class]);

        $this->app->instance(EmailProvider::class, new class implements EmailProvider
        {
            public function send(EmailMessage $message): SentEmail
            {
                $call = count(ScheduledEmailSendOnceTest::$providerCalls) + 1;
                ScheduledEmailSendOnceTest::$providerCalls[] = $message->toEmail;

                if (ScheduledEmailSendOnceTest::$failOnCall === $call) {
                    throw new RuntimeException("SMTP connection refused (call {$call})");
                }

                return new SentEmail(provider: 'smtp', messageId: "send-once-{$call}@crm.test");
            }
        });
    }

    /** @return array{0: User, 1: Lead, 2: Email} a BD, their lead, and a DUE scheduled email */
    private function dueScheduledEmail(): array
    {
        $bd = User::factory()->create(['status' => 'active']);
        $bd->assignRole('crm_bd');
        $lead = Lead::factory()->create(['assigned_to' => $bd->id, 'email' => 'prospect@example.test', 'cold_calling' => 'not-called']);
        $email = Email::create([
            'lead_id' => $lead->id,
            'user_id' => $bd->id,
            'direction' => 'outbound',
            'to_email' => 'prospect@example.test',
            'subject' => 'Your website proposal',
            'body' => 'Hello — here is the proposal we discussed.',
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinute(),
        ]);

        return [$bd, $lead, $email];
    }

    private function sendNow(User $bd, Email $email): TestResponse
    {
        Sanctum::actingAs($bd);

        return $this->postJson("/api/v1/crm/emails/{$email->id}/send-now");
    }

    private function runScheduler(): void
    {
        (new SendScheduledEmailsJob)->handle(app(ScheduledEmailService::class));
    }

    /** Runs `$competitor` the first time Send Now's request loads this email — the race window. */
    private function whenSendNowLoads(Email $email, callable $competitor, bool &$fired): void
    {
        Email::retrieved(function (Email $model) use ($email, $competitor, &$fired): void {
            if ($fired || $model->id !== $email->id) {
                return;
            }
            $fired = true;
            $competitor();
        });
    }

    private function sentActivities(Lead $lead): int
    {
        return Activity::query()->where('subject_id', $lead->id)->where('event', 'email.sent')->count();
    }

    /** @return list<string> */
    private function timeline(Email $email): array
    {
        return EmailEvent::query()->where('email_id', $email->id)->orderBy('id')->pluck('type')->all();
    }

    // ── 1. The races ───────────────────────────────────────────────────────────────────────────

    public function test_the_scheduler_sending_inside_send_nows_window_delivers_once_and_send_now_gets_422(): void
    {
        [$bd, $lead, $email] = $this->dueScheduledEmail();
        $fired = false;
        $this->whenSendNowLoads($email, fn () => $this->runScheduler(), $fired);

        $this->sendNow($bd, $email)->assertStatus(422)->assertJsonPath('errors.email.0', self::NOT_PENDING);

        $this->assertTrue($fired, 'the race window was never exercised');
        $this->assertCount(1, self::$providerCalls, 'the prospect must receive the email exactly once');
        $this->assertSame(1, $this->sentActivities($lead), 'exactly one email.sent Activity');
        Event::assertDispatchedTimes(OutboundEmailSent::class, 1);
        $this->assertSame('sent', $email->fresh()->status);
        $this->assertSame(['queued', 'sending', 'sent'], $this->timeline($email), 'the losing Send Now records nothing');
    }

    public function test_two_overlapping_send_now_requests_deliver_once_and_the_second_gets_422(): void
    {
        [$bd, $lead, $email] = $this->dueScheduledEmail();
        $fired = false;
        $competitor = null;
        $this->whenSendNowLoads($email, function () use ($bd, $email, &$competitor): void {
            $competitor = $this->sendNow($bd, $email)->status();
        }, $fired);

        $this->sendNow($bd, $email)->assertStatus(422)->assertJsonPath('errors.email.0', self::NOT_PENDING);

        $this->assertTrue($fired, 'the race window was never exercised');
        $this->assertSame(200, $competitor, 'the first Send Now is the one that sends');
        $this->assertCount(1, self::$providerCalls);
        $this->assertSame(1, $this->sentActivities($lead));
        Event::assertDispatchedTimes(OutboundEmailSent::class, 1);
        $this->assertSame(['sending', 'sent'], $this->timeline($email));
    }

    /** The losing path never reaches the provider, so it cannot turn a delivered email into `failed`. */
    public function test_a_losing_send_cannot_mark_a_delivered_email_failed(): void
    {
        [$bd, $lead, $email] = $this->dueScheduledEmail();
        self::$failOnCall = 2; // a second provider call would fail — and must never happen
        $fired = false;
        $this->whenSendNowLoads($email, fn () => $this->runScheduler(), $fired);

        $this->sendNow($bd, $email)->assertStatus(422);

        $this->assertTrue($fired);
        $fresh = $email->fresh();
        $this->assertSame('sent', $fresh->status);
        $this->assertNull($fresh->failed_at);
        $this->assertNull($fresh->error);
        $this->assertNotContains('failed', $this->timeline($email));
        $this->assertCount(1, self::$providerCalls);
    }

    /** A stale copy handed to deliver() (a late listener, a retry) sends nothing once another sender owns the row. */
    public function test_deliver_with_a_stale_copy_does_not_send_again_or_record_anything(): void
    {
        [, $lead, $email] = $this->dueScheduledEmail();
        $service = app(ScheduledEmailService::class);
        $claimed = $service->claim($email);
        $staleCopy = clone $claimed; // still reads `queued`

        $service->deliver($claimed);
        $timelineAfterFirst = $this->timeline($email);

        $result = $service->deliver($staleCopy);

        $this->assertSame('sent', $result->status, 'deliver() hands back the row as it really is');
        $this->assertCount(1, self::$providerCalls);
        $this->assertSame(1, $this->sentActivities($lead));
        $this->assertSame($timelineAfterFirst, $this->timeline($email), 'no duplicate sending/sent events');
    }

    // ── 2. Normal behaviour is unchanged ───────────────────────────────────────────────────────

    public function test_send_now_still_sends_a_scheduled_email(): void
    {
        [$bd, $lead, $email] = $this->dueScheduledEmail();

        $this->sendNow($bd, $email)->assertOk();

        $fresh = $email->fresh();
        $this->assertSame('sent', $fresh->status);
        $this->assertNotNull($fresh->sent_at);
        $this->assertNotNull($fresh->queued_at, 'Send Now still stamps queued_at as before');
        $this->assertSame(0, (int) $fresh->attempts, 'Send Now never took the scheduler claim, and still does not');
        $this->assertSame(['sending', 'sent'], $this->timeline($email));
        $this->assertSame(['prospect@example.test'], self::$providerCalls);
        $this->assertSame(1, $this->sentActivities($lead));
        Event::assertDispatchedTimes(OutboundEmailSent::class, 1);
    }

    public function test_the_scheduler_lifecycle_is_unchanged(): void
    {
        Event::fake([OutboundEmailSent::class, ScheduledEmailDue::class]);
        [, $lead, $email] = $this->dueScheduledEmail();

        $this->runScheduler();
        Event::assertDispatchedTimes(ScheduledEmailDue::class, 1);
        $this->assertSame('queued', $email->fresh()->status, 'the claim is still scheduled → queued');
        $this->assertSame(1, (int) $email->fresh()->attempts);

        app(ScheduledEmailService::class)->deliver($email->fresh());

        $fresh = $email->fresh();
        $this->assertSame('sent', $fresh->status);
        $this->assertSame(1, (int) $fresh->attempts);
        $this->assertSame(['queued', 'sending', 'sent'], $this->timeline($email));
        $this->assertCount(1, self::$providerCalls);
        $this->assertSame(1, $this->sentActivities($lead));
    }

    public function test_a_transport_failure_still_marks_the_email_failed_on_send_now(): void
    {
        [$bd, $lead, $email] = $this->dueScheduledEmail();
        self::$failOnCall = 1;

        $this->sendNow($bd, $email)->assertOk()->assertJsonPath('data.status', 'failed');

        $fresh = $email->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertNotNull($fresh->failed_at);
        $this->assertStringContainsString('SMTP connection refused', (string) $fresh->error);
        $this->assertSame(['sending', 'failed'], $this->timeline($email));
        $this->assertSame(0, $this->sentActivities($lead));
    }

    public function test_a_transport_failure_still_marks_the_email_failed_on_the_scheduler_path(): void
    {
        [, $lead, $email] = $this->dueScheduledEmail();
        self::$failOnCall = 1;

        $this->runScheduler();

        $fresh = $email->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertSame(['queued', 'sending', 'failed'], $this->timeline($email));
        $this->assertSame(0, $this->sentActivities($lead));
    }

    public function test_scheduler_then_send_now_is_still_refused_by_the_policy(): void
    {
        [$bd, , $email] = $this->dueScheduledEmail();

        $this->runScheduler();
        $this->sendNow($bd, $email)->assertForbidden();

        $this->assertCount(1, self::$providerCalls);
    }

    public function test_send_now_then_scheduler_is_still_skipped_by_the_scheduler(): void
    {
        [$bd, $lead, $email] = $this->dueScheduledEmail();

        $this->sendNow($bd, $email)->assertOk();
        $this->runScheduler();

        $this->assertCount(1, self::$providerCalls);
        $this->assertSame(1, $this->sentActivities($lead));
        $this->assertSame(['sending', 'sent'], $this->timeline($email));
    }
}
