<?php

namespace Tests\Feature\Mail;

use App\Models\User;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Notifications\InboundReplyMailNotification;
use App\Modules\Crm\Services\Mail\Contracts\EmailProvider;
use App\Modules\Crm\Services\Mail\EmailMessage;
use App\Modules\Crm\Services\Mail\SentEmail;
use App\Modules\Crm\Services\Outreach\OutreachEmailService;
use App\Modules\Hr\Mail\CandidateNotificationMail;
use App\Modules\Hr\Models\Candidate;
use App\Modules\Hr\Models\Requisition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The platform is one application sending as several businesses over ONE shared relay.
 * These tests pin that each module's mail leaves under its own identity, and that the
 * CRM's identity stays welded to the mailbox inbound sync polls.
 *
 * Transport is deliberately never asserted per module: all three go out through the same
 * configured mailer (`MAIL_MAILER=array` under phpunit), which is the point of the design.
 */
class ModuleSenderIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('mail.module_senders', [
            'crm' => ['address' => 'business@viteseo.test', 'name' => 'Zerem CRM'],
            'hr' => ['address' => 'hris@viteseo.test', 'name' => 'Vite SEO HR'],
            'pms' => ['address' => 'pms@viteseo.test', 'name' => 'Vite SEO Projects'],
        ]);
        config()->set('mail.from', ['address' => 'platform@viteseo.test', 'name' => 'Vite SEO']);
    }

    /** The From header of the last message the shared transport actually accepted. */
    private function lastSentFrom(): string
    {
        $transport = Mail::mailer()->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport, 'phpunit.xml must pin MAIL_MAILER=array.');

        $messages = $transport->messages();
        $this->assertNotEmpty($messages, 'No message reached the transport.');

        return $messages->last()->getOriginalMessage()->getFrom()[0]->toString();
    }

    // ── CRM: customer outreach ──────────────────────────────────────────────────────

    public function test_crm_outreach_sends_under_the_crm_identity(): void
    {
        $captured = null;
        $this->app->instance(EmailProvider::class, new class($captured) implements EmailProvider
        {
            public function __construct(public mixed &$captured) {}

            public function send(EmailMessage $message): SentEmail
            {
                $this->captured = $message;

                return new SentEmail(provider: 'smtp', messageId: 'id@crm.test');
            }
        });

        $sender = User::factory()->create();
        $lead = Lead::factory()->create(['assigned_to' => $sender->id, 'email' => 'prospect@acme.test']);

        $email = app(OutreachEmailService::class)->sendToLead($lead, $sender, 'Hello', 'Body copy');

        // The identity handed to the transport…
        $this->assertSame('business@viteseo.test', $this->app->make(EmailProvider::class)->captured->fromEmail);
        $this->assertSame('Zerem CRM', $this->app->make(EmailProvider::class)->captured->fromName);

        // …and the identity recorded in lead email history, which the UI renders.
        $this->assertSame('business@viteseo.test', $email->from_email);
        $this->assertSame('Zerem CRM', $email->from_name);
    }

    // ── CRM: internal staff alert ───────────────────────────────────────────────────

    /**
     * The staff alert shares the CRM's identity (PO decision) rather than the platform
     * default: an alert about a Zerem lead should arrive from Zerem even though HR and PMS
     * share the relay. It remains a staff alert, not customer mail — it writes no
     * crm_emails row, which {@see \Tests\Feature\Outreach\InboundReplyInternalMailTest} pins.
     */
    public function test_the_crm_internal_staff_alert_sends_under_the_crm_identity(): void
    {
        $bd = User::factory()->create(['email' => 'bruce@viteseo.test']);
        $lead = Lead::factory()->create(['assigned_to' => $bd->id, 'business_name' => 'Acme Corp']);
        $reply = Email::create([
            'lead_id' => $lead->id,
            'direction' => 'inbound',
            'from_email' => 'prospect@acme.test',
            'from_name' => 'A Prospect',
            'to_email' => 'business@viteseo.test',
            'subject' => 'Re: Hello',
            'body' => 'Sounds good.',
            'status' => 'received',
        ]);

        $bd->notify(new InboundReplyMailNotification($lead, $reply));

        $this->assertSame('"Zerem CRM" <business@viteseo.test>', $this->lastSentFrom());
    }

    // ── HR: candidate mail ──────────────────────────────────────────────────────────

    public function test_hr_candidate_mail_sends_under_the_hr_identity(): void
    {
        // Built rather than persisted: HR has no factories, and the envelope under test reads
        // only these attributes. Sent unqueued, so nothing is serialized.
        $requisition = new Requisition(['title' => 'Backend Engineer']);
        $candidate = new Candidate(['name' => 'Jordan Cruz', 'email' => 'applicant@example.test']);

        Mail::to($candidate->email)->send(new CandidateNotificationMail($candidate, $requisition, 'offer'));

        $this->assertSame('"Vite SEO HR" <hris@viteseo.test>', $this->lastSentFrom());
    }

    // ── The invariant ───────────────────────────────────────────────────────────────

    /**
     * GUARD. The CRM's From address and the mailbox Gmail sync polls must be the same
     * inbox. Split them and prospects reply somewhere nobody reads: inbound threading
     * stops with no error anywhere. Asserted against the real config files under the env
     * shapes a deployment can actually be in, because the chains being tested live in
     * config/mail.php and config/outreach.php.
     */
    #[DataProvider('deploymentEnvShapes')]
    public function test_the_crm_sender_and_the_polled_mailbox_stay_the_same_address(array $env, string $because): void
    {
        [$sender, $mailbox] = $this->resolveUnderEnv($env);

        $this->assertSame($sender, $mailbox, $because);
    }

    /** @return array<string, array{0: array<string, string>, 1: string}> */
    public static function deploymentEnvShapes(): array
    {
        return [
            'nothing but the framework default' => [
                ['MAIL_FROM_ADDRESS' => 'platform@viteseo.test'],
                'With no CRM vars set, both sides fall back to the same global address.',
            ],
            'production today (OUTREACH_FROM_ADDRESS + OUTREACH_MAILBOX)' => [
                [
                    'MAIL_FROM_ADDRESS' => 'platform@viteseo.test',
                    'OUTREACH_FROM_ADDRESS' => 'business@viteseo.test',
                    'OUTREACH_MAILBOX' => 'business@viteseo.test',
                ],
                'The shipped production shape must stay aligned.',
            ],
            'legacy sender without an explicit mailbox' => [
                ['MAIL_FROM_ADDRESS' => 'platform@viteseo.test', 'OUTREACH_FROM_ADDRESS' => 'business@viteseo.test'],
                'OUTREACH_MAILBOX defaults to the CRM sender, not to MAIL_FROM_ADDRESS.',
            ],
            'new per-module var alone' => [
                ['MAIL_FROM_ADDRESS' => 'platform@viteseo.test', 'CRM_MAIL_FROM_ADDRESS' => 'business@viteseo.test'],
                'Setting CRM_MAIL_FROM_ADDRESS alone must move the polled mailbox with it.',
            ],
        ];
    }

    /**
     * Loads the real config/mail.php + config/outreach.php under a given env shape.
     *
     * @return array{0: string, 1: string} [crm sender address, polled mailbox]
     */
    private function resolveUnderEnv(array $env): array
    {
        $keys = ['MAIL_FROM_ADDRESS', 'OUTREACH_FROM_ADDRESS', 'CRM_MAIL_FROM_ADDRESS', 'OUTREACH_MAILBOX'];
        $restore = [];

        foreach ($keys as $key) {
            $restore[$key] = $_SERVER[$key] ?? null;
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        foreach ($env as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        try {
            $mail = require base_path('config/mail.php');
            $outreach = require base_path('config/outreach.php');

            return [(string) $mail['module_senders']['crm']['address'], (string) $outreach['mailbox']];
        } finally {
            foreach ($restore as $key => $value) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);

                if ($value !== null) {
                    putenv("{$key}={$value}");
                    $_ENV[$key] = $_SERVER[$key] = $value;
                }
            }
        }
    }
}
