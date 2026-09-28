<?php

namespace Tests\Feature\Crm;

use App\Models\Org;
use App\Models\User;
use App\Modules\Crm\Jobs\SendScheduledEmailsJob;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\Lead;
use App\Services\OrgOnboardingService;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Mime\Email as MimeEmail;
use Tests\TestCase;

/**
 * Formatted email replies, end to end: POST /leads/{lead}/emails with `body_doc`.
 *
 * The transport is Laravel's `array` mailer (pinned by phpunit.xml), so every assertion about the
 * message is made against what the SMTP provider actually handed to Symfony Mailer — its parts, its
 * HTML, its text — not against an intermediate object that could drift from what goes out.
 *
 * What must hold, and is asserted directly:
 *  1. A formatted reply goes out as multipart/alternative: text/plain AND text/html.
 *  2. Without `body_doc` nothing changes — one text/plain part, `body` exactly as submitted.
 *  3. The text part and the stored body are the server's projection of the document, never the
 *     client's `body` — the two parts cannot disagree.
 *  4. The document is validated before anything is stored or sent, and text is escaped.
 *  5. A scheduled formatted email keeps its formatting until the scheduler sends it.
 *  6. Authorization and tenant isolation are exactly those of the plain send.
 */
class EmailRichTextSendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::mailer()->getSymfonyTransport()->flush();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────────────────

    private function admin(): User
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('crm_admin');

        return $admin;
    }

    private function businessDeveloper(): User
    {
        $bd = User::factory()->create(['status' => 'active']);
        $bd->assignRole('crm_bd');

        return $bd;
    }

    private function lead(array $attributes = []): Lead
    {
        return Lead::factory()->create(array_merge(['email' => 'prospect@acme.test'], $attributes));
    }

    /** "Hi **Maria**" then a one-item bulleted list. */
    private function formattedDoc(): array
    {
        return [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [
                    ['type' => 'text', 'text' => 'Hi '],
                    ['type' => 'text', 'text' => 'Maria', 'marks' => [['type' => 'bold']]],
                ]],
                ['type' => 'bulletList', 'content' => [
                    ['type' => 'listItem', 'content' => [
                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Proposal attached']]],
                    ]],
                ]],
            ],
        ];
    }

    private function send(Lead $lead, array $payload)
    {
        return $this->postJson("/api/v1/crm/leads/{$lead->id}/emails", $payload);
    }

    private function sentCount(): int
    {
        return Mail::mailer()->getSymfonyTransport()->messages()->count();
    }

    private function lastSent(): MimeEmail
    {
        $message = Mail::mailer()->getSymfonyTransport()->messages()->last()?->getOriginalMessage();
        $this->assertInstanceOf(MimeEmail::class, $message, 'Expected an email to have been sent.');

        return $message;
    }

    // ── 1. Formatted replies are multipart ───────────────────────────────────────────────────

    public function test_a_formatted_reply_is_sent_as_multipart_plain_text_and_html(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = $this->lead();

        $this->send($lead, ['subject' => 'Re: Proposal', 'body' => 'Hi Maria', 'body_doc' => $this->formattedDoc()])
            ->assertCreated()
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.bodyDoc.type', 'doc');

        $message = $this->lastSent();
        $raw = $message->toString();

        $this->assertStringContainsString('multipart/alternative', $raw);
        $this->assertStringContainsString('text/plain', $raw);
        $this->assertStringContainsString('text/html', $raw);

        $this->assertSame("Hi Maria\n• Proposal attached", (string) $message->getTextBody());
        $html = (string) $message->getHtmlBody();
        $this->assertStringContainsString('Hi <strong>Maria</strong>', $html);
        $this->assertStringContainsString('<ul style="margin: 0; padding-left: 24px;"><li>', $html);
    }

    public function test_the_stored_body_is_the_servers_projection_not_the_clients(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = $this->lead();

        // The client's `body` says something else entirely; it must not be what is stored or sent.
        $this->send($lead, ['subject' => 'Re: Proposal', 'body' => 'Totally different text', 'body_doc' => $this->formattedDoc()])
            ->assertCreated();

        $email = Email::firstOrFail();
        $this->assertSame("Hi Maria\n• Proposal attached", $email->body);
        $this->assertSame($this->formattedDoc(), $email->body_doc);
        $this->assertSame($email->body, (string) $this->lastSent()->getTextBody());
    }

    public function test_spaces_between_differently_formatted_words_survive_the_request(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = $this->lead();

        // "Hello " + bold "world" — global TrimStrings would turn this into "Helloworld" if `body_doc`
        // were not exempt from it.
        $doc = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Hello '],
            ['type' => 'text', 'text' => 'world', 'marks' => [['type' => 'bold']]],
        ]]]];

        $this->send($lead, ['subject' => 'Hi', 'body' => 'Hello world', 'body_doc' => $doc])->assertCreated();

        $this->assertSame('Hello world', (string) $this->lastSent()->getTextBody());
        $this->assertStringContainsString('Hello <strong>world</strong>', (string) $this->lastSent()->getHtmlBody());
    }

    // ── 2. Plain text is untouched ───────────────────────────────────────────────────────────

    public function test_a_plain_reply_is_sent_exactly_as_before(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = $this->lead();

        $this->send($lead, ['subject' => 'Hello', 'body' => "Line one\nLine two"])
            ->assertCreated()
            ->assertJsonPath('data.bodyDoc', null);

        $message = $this->lastSent();
        $this->assertSame("Line one\nLine two", (string) $message->getTextBody());
        $this->assertNull($message->getHtmlBody(), 'A plain send must not gain an HTML part.');
        $this->assertStringNotContainsString('text/html', $message->toString());

        $email = Email::firstOrFail();
        $this->assertSame("Line one\nLine two", $email->body);
        $this->assertNull($email->body_doc);
    }

    public function test_an_explicit_null_document_is_a_plain_send(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = $this->lead();

        $this->send($lead, ['subject' => 'Hello', 'body' => 'Just text', 'body_doc' => null])->assertCreated();

        $this->assertNull($this->lastSent()->getHtmlBody());
        $this->assertSame('Just text', Email::firstOrFail()->body);
    }

    public function test_older_emails_without_a_document_still_render_in_the_thread(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = $this->lead();

        Email::create([
            'lead_id' => $lead->id,
            'direction' => 'outbound',
            'to_email' => 'prospect@acme.test',
            'subject' => 'Sent before rich text existed',
            'body' => 'A plain body from last month',
            'status' => 'sent',
            'sent_at' => now()->subMonth(),
        ]);

        $this->getJson("/api/v1/crm/leads/{$lead->id}/emails")
            ->assertOk()
            ->assertJsonPath('data.0.body', 'A plain body from last month')
            ->assertJsonPath('data.0.bodyDoc', null);
    }

    // ── 4. Validation and escaping ───────────────────────────────────────────────────────────

    public function test_an_unsupported_document_is_refused_before_anything_is_stored_or_sent(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = $this->lead();

        $heading = ['type' => 'doc', 'content' => [['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'Big']]]]];

        $this->send($lead, ['subject' => 'Hi', 'body' => 'Big', 'body_doc' => $heading])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body_doc');

        $this->assertSame(0, Email::count());
        $this->assertSame(0, $this->sentCount());
    }

    public function test_html_is_never_accepted_in_place_of_a_document(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = $this->lead();

        $this->send($lead, ['subject' => 'Hi', 'body' => 'x', 'body_doc' => '<p onclick="x">hi</p>'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body_doc');

        $this->assertSame(0, $this->sentCount());
    }

    public function test_a_document_that_reads_as_nothing_is_refused(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = $this->lead();

        $blank = ['type' => 'doc', 'content' => [['type' => 'paragraph'], ['type' => 'paragraph']]];

        $this->send($lead, ['subject' => 'Hi', 'body' => 'x', 'body_doc' => $blank])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body_doc');
    }

    public function test_markup_typed_as_text_is_escaped_in_the_sent_html(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = $this->lead();

        $doc = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [[
            'type' => 'text',
            'text' => '<script>alert(1)</script><img src=x onerror=alert(1)>',
            'marks' => [['type' => 'bold']],
        ]]]]];

        $this->send($lead, ['subject' => 'Hi', 'body' => 'x', 'body_doc' => $doc])->assertCreated();

        $html = (string) $this->lastSent()->getHtmlBody();
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    // ── 5. Scheduled emails keep their formatting ────────────────────────────────────────────

    public function test_a_scheduled_formatted_email_keeps_its_formatting_until_it_is_sent(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = $this->lead();

        $this->send($lead, [
            'subject' => 'Later',
            'body' => 'Hi Maria',
            'body_doc' => $this->formattedDoc(),
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])->assertCreated()->assertJsonPath('data.status', 'scheduled');

        $this->assertSame(0, $this->sentCount(), 'Scheduling must not send.');
        $this->assertSame($this->formattedDoc(), Email::firstOrFail()->body_doc);

        // The real scheduler path: the job re-reads the row when it is due.
        $this->travel(2)->days();
        SendScheduledEmailsJob::dispatchSync();

        $this->assertSame('sent', Email::withoutGlobalScopes()->firstOrFail()->status);
        $message = $this->lastSent();
        $this->assertSame("Hi Maria\n• Proposal attached", (string) $message->getTextBody());
        $this->assertStringContainsString('Hi <strong>Maria</strong>', (string) $message->getHtmlBody());
    }

    public function test_a_plain_scheduled_email_still_sends_as_plain_text(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = $this->lead();

        $this->send($lead, [
            'subject' => 'Later',
            'body' => 'Plain later',
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])->assertCreated();

        $this->travel(2)->days();
        SendScheduledEmailsJob::dispatchSync();

        $message = $this->lastSent();
        $this->assertSame('Plain later', (string) $message->getTextBody());
        $this->assertNull($message->getHtmlBody());
    }

    public function test_a_stored_document_that_no_longer_validates_is_sent_as_plain_text_only(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = $this->lead();
        $sender = User::factory()->create(['status' => 'active']);

        // A row whose document was tampered with at rest: it must never be rendered.
        Email::create([
            'lead_id' => $lead->id,
            'user_id' => $sender->id,
            'direction' => 'outbound',
            'to_email' => 'prospect@acme.test',
            'subject' => 'Tampered',
            'body' => 'Plain fallback',
            'body_doc' => ['type' => 'doc', 'content' => [['type' => 'script', 'content' => [['type' => 'text', 'text' => 'x']]]]],
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinute(),
        ]);

        SendScheduledEmailsJob::dispatchSync();

        $message = $this->lastSent();
        $this->assertSame('Plain fallback', (string) $message->getTextBody());
        $this->assertNull($message->getHtmlBody());
    }

    // ── 6. Authorization and tenant isolation are unchanged ──────────────────────────────────

    public function test_the_owning_business_developer_can_send_a_formatted_reply(): void
    {
        $bd = $this->businessDeveloper();
        Sanctum::actingAs($bd);
        $lead = $this->lead(['assigned_to' => $bd->id]);

        $this->send($lead, ['subject' => 'Hi', 'body' => 'x', 'body_doc' => $this->formattedDoc()])->assertCreated();

        $this->assertNotNull($this->lastSent()->getHtmlBody());
    }

    public function test_a_business_developer_cannot_email_a_lead_they_do_not_own(): void
    {
        $owner = $this->businessDeveloper();
        Sanctum::actingAs($this->businessDeveloper());
        $lead = $this->lead(['assigned_to' => $owner->id]);

        $this->send($lead, ['subject' => 'Hi', 'body' => 'x', 'body_doc' => $this->formattedDoc()])->assertForbidden();

        $this->assertSame(0, Email::count());
        $this->assertSame(0, $this->sentCount());
    }

    public function test_sending_requires_authentication(): void
    {
        $this->postJson('/api/v1/crm/leads/1/emails', ['subject' => 'Hi', 'body' => 'x', 'body_doc' => $this->formattedDoc()])
            ->assertUnauthorized();
    }

    public function test_another_tenants_lead_cannot_be_emailed(): void
    {
        [$theirOrg] = $this->orgWithAdmin('theirs');
        [, $ourAdmin] = $this->orgWithAdmin('ours');

        $theirLead = CurrentOrg::runAs($theirOrg->id, fn (): Lead => $this->lead());

        CurrentOrg::clear();
        Sanctum::actingAs($ourAdmin);

        $this->send($theirLead, ['subject' => 'Hi', 'body' => 'x', 'body_doc' => $this->formattedDoc()])->assertNotFound();

        $this->assertSame(0, Email::withoutGlobalScopes()->count());
        $this->assertSame(0, $this->sentCount());
    }

    /** @return array{Org, User} */
    private function orgWithAdmin(string $slug): array
    {
        $org = app(OrgOnboardingService::class)->createOrg(
            "Org {$slug}", "Owner {$slug}", "owner-{$slug}@client.ph", 'password-123', ['crm' => 'full'],
        )['org'];

        $admin = User::factory()->orgless()->create(['status' => 'active', 'name' => "Admin {$slug}"]);
        $admin->assignRole('crm_admin');
        $org->users()->attach($admin->id, ['is_default' => true]);

        return [$org, $admin];
    }
}
