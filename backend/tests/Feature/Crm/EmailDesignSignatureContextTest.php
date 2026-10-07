<?php

namespace Tests\Feature\Crm;

use App\Models\Org;
use App\Models\User;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\EmailDesign;
use App\Modules\Crm\Models\EmailTemplate;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Outreach\EmailTemplateDesignService;
use App\Modules\Crm\Services\Outreach\TemplateRenderer;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Mime\Email as MimeEmail;
use Tests\TestCase;
use Tests\Unit\Crm\EmailDesign\BuildsEmailDesigns;

/**
 * The signature switches reach real values: the organization's name, the CRM mailbox every CRM email is sent
 * from (and replies land in), and the SENDING BD's own phone (users.phone) — through the one send context, so
 * the preview, the message on the wire and body_html stay the same bytes.
 */
class EmailDesignSignatureContextTest extends TestCase
{
    use BuildsEmailDesigns;
    use RefreshDatabase;

    private const MAILBOX = 'business@acme.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['mail.module_senders.crm.address' => self::MAILBOX]);
        Mail::mailer()->getSymfonyTransport()->flush();
    }

    private function sender(?string $phone): User
    {
        $sender = User::factory()->create(['status' => 'active', 'name' => 'Uriel Papa', 'phone' => $phone]);
        $sender->assignRole('crm_admin');

        return $sender;
    }

    private function lead(): Lead
    {
        return Lead::factory()->create(['email' => 'sarah@revora.test', 'contact_name' => 'Sarah Cruz', 'business_name' => 'Revora']);
    }

    private function switchedDesign(): EmailDesign
    {
        return EmailDesign::create([
            'name' => 'Proposal',
            'is_default' => false,
            'layout' => $this->layout([], [], signature: ['align' => 'center', 'show_company' => true, 'show_email' => true, 'show_phone' => true]),
        ]);
    }

    private function template(EmailDesign $design): EmailTemplate
    {
        $content = $this->content([$this->textBlock()]);

        return EmailTemplate::create([
            'purpose' => 'Follow-up',
            'tone' => 'Warm & professional',
            'subject' => 'Your quotation',
            'body' => app(EmailTemplateDesignService::class)->plainTextBody($design->layout, $content),
            'content' => $content,
            'email_design_id' => $design->id,
            'is_active' => true,
        ]);
    }

    private function sendTemplate(Lead $lead, User $sender, EmailTemplate $template): int
    {
        return $this->postJson("/api/v1/crm/leads/{$lead->id}/emails", [
            'subject' => 'Your quotation',
            'body' => app(TemplateRenderer::class)->render($template->subject, $template->body, $lead, $sender)['body'],
            'email_template_id' => (string) $template->id,
        ])->assertCreated()->json('data.id');
    }

    private function lastSent(): MimeEmail
    {
        $message = Mail::mailer()->getSymfonyTransport()->messages()->last()?->getOriginalMessage();
        $this->assertInstanceOf(MimeEmail::class, $message);

        return $message;
    }

    private function orgName(): string
    {
        return (string) Org::query()->whereKey(CurrentOrg::id())->value('name');
    }

    public function test_the_send_context_carries_the_org_name_the_crm_mailbox_and_the_senders_phone(): void
    {
        $sender = $this->sender('0945 449 3862');
        $context = app(EmailTemplateDesignService::class)->sendContext($this->lead(), $sender);

        $this->assertSame($this->orgName(), $context->organizationName);
        $this->assertSame(self::MAILBOX, $context->organizationEmail);
        $this->assertSame('0945 449 3862', $context->senderPhone);
        $this->assertSame([], $context->previewImages);
    }

    public function test_a_designed_template_with_the_switches_previews_sends_and_stores_the_same_signature(): void
    {
        $sender = $this->sender('0945 449 3862');
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $template = $this->template($this->switchedDesign());

        $preview = $this->getJson("/api/v1/crm/leads/{$lead->id}/email-templates/{$template->id}/preview")->assertOk()->json('data.html');
        $id = $this->sendTemplate($lead, $sender, $template);
        $sent = (string) $this->lastSent()->getHtmlBody();

        $this->assertSame($preview, $sent);
        $this->assertSame($sent, Email::query()->findOrFail($id)->body_html);
        $this->assertStringContainsString('>'.e($this->orgName()).'</p>', $sent);
        $this->assertStringContainsString('<a href="mailto:'.self::MAILBOX.'"', $sent);
        $this->assertStringContainsString('<a href="tel:09454493862"', $sent);
        $this->assertStringContainsString('<strong>Uriel Papa</strong>', $sent);
    }

    public function test_a_bd_without_a_phone_sends_the_signature_without_one(): void
    {
        $sender = $this->sender(null);
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $template = $this->template($this->switchedDesign());

        $this->sendTemplate($lead, $sender, $template);
        $sent = (string) $this->lastSent()->getHtmlBody();

        $this->assertStringNotContainsString('tel:', $sent);
        $this->assertStringNotContainsString('&middot;', $sent);
        $this->assertStringContainsString('<a href="mailto:'.self::MAILBOX.'"', $sent);
    }

    public function test_the_signature_names_whoever_sends_not_whoever_wrote_the_template(): void
    {
        $author = $this->sender('0917 000 0000');
        $template = $this->template($this->switchedDesign());
        $other = $this->sender('0918 111 2222');
        Sanctum::actingAs($other);

        $this->sendTemplate($this->lead(), $other, $template);
        $sent = (string) $this->lastSent()->getHtmlBody();

        $this->assertStringContainsString('tel:09181112222', $sent);
        $this->assertStringNotContainsString('0917 000 0000', $sent);
        $this->assertNotNull($author->id);
    }

    public function test_a_designed_reply_with_the_switches_previews_and_sends_the_same_parts(): void
    {
        $sender = $this->sender('0945 449 3862');
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $design = $this->switchedDesign();
        $payload = ['email_design_id' => (string) $design->id, 'body_doc' => $this->doc($this->para($this->text('Thanks, Sarah.')))];

        $preview = $this->postJson("/api/v1/crm/leads/{$lead->id}/emails/design-preview", $payload)->assertOk()->json('data.html');
        $id = $this->postJson("/api/v1/crm/leads/{$lead->id}/emails", [...$payload, 'subject' => 'Re: Your quotation', 'body' => 'Thanks, Sarah.'])
            ->assertCreated()->json('data.id');
        $sent = $this->lastSent();

        $this->assertSame($preview, (string) $sent->getHtmlBody());
        $this->assertSame($preview, Email::query()->findOrFail($id)->body_html);
        $this->assertStringEndsWith($this->orgName()."\n".self::MAILBOX.' · 0945 449 3862', (string) $sent->getTextBody());
    }

    public function test_the_settings_preview_shows_the_real_mailbox_and_a_sample_phone(): void
    {
        Sanctum::actingAs($this->sender('0945 449 3862'));

        $html = $this->postJson('/api/v1/crm/settings/email-designs/preview', [
            'layout' => json_encode($this->switchedDesign()->layout),
        ])->assertOk()->json('data.html');

        $this->assertStringContainsString('<a href="mailto:'.self::MAILBOX.'"', $html);
        $this->assertStringContainsString('tel:+639171234567', $html);
    }

    public function test_the_gmail_guard_measures_the_signature_lines_a_design_switches_on(): void
    {
        $designs = app(EmailTemplateDesignService::class);
        $content = $this->content([$this->textBlock()]);

        $plain = $designs->htmlBytes($this->layout([], []), $content);
        $switched = $designs->htmlBytes($this->switchedDesign()->layout, $content);

        $this->assertGreaterThan($plain, $switched);
    }
}
