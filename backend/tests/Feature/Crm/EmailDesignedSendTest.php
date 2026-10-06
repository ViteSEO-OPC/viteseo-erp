<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Jobs\SendScheduledEmailsJob;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\EmailDesign;
use App\Modules\Crm\Models\EmailTemplate;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Mail\Contracts\EmailProvider;
use App\Modules\Crm\Services\Mail\EmailDocument;
use App\Modules\Crm\Services\Mail\EmailMessage;
use App\Modules\Crm\Services\Mail\SentEmail;
use App\Modules\Crm\Services\Outreach\EmailBrandingService;
use App\Modules\Crm\Services\Outreach\EmailTemplateDesignService;
use App\Modules\Crm\Services\Outreach\OutreachEmailService;
use App\Modules\Crm\Services\Outreach\TemplateRenderer;
use App\Modules\Crm\Support\EmailDesign\EmailDesignRenderer;
use App\Services\OrgOnboardingService;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Mime\Email as MimeEmail;
use Tests\TestCase;
use Tests\Unit\Crm\EmailDesign\BuildsEmailDesigns;

/**
 * PREVIEW = SEND. The one claim this feature exists to make true.
 *
 * A designer who looks at the Settings preview is entitled to believe a recipient gets that email.
 * Until now they did not: the design was rendered for the preview, flattened to plain text for
 * storage, and the flattened text was what went out. These tests assert the promise directly —
 * against the SYMFONY MESSAGE the transport actually received, not an intermediate object that
 * could drift from what leaves the building. The `array` mailer is pinned by phpunit.xml.
 *
 * THE CENTRAL TEST is `test_the_preview_html_and_the_sent_html_are_identical`: it renders the
 * preview and sends the email, then compares the two strings byte for byte. One renderer, one
 * context, one result — if anyone ever adds a second rendering path, that test fails.
 *
 * The rest is the failure catalogue a designed send has to survive, and every one of them resolves
 * the same way: fall back to the plain text this email was always able to send, and say why in the
 * log. A half-rendered design is not a smaller design; it is an email nobody approved.
 */
class EmailDesignedSendTest extends TestCase
{
    use BuildsEmailDesigns;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::mailer()->getSymfonyTransport()->flush();
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────────────────────

    private function admin(): User
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('crm_admin');

        return $admin;
    }

    private function lead(array $attributes = []): Lead
    {
        return Lead::factory()->create(array_merge([
            'email' => 'prospect@acme.test',
            'contact_name' => 'Maria Santos',
            'business_name' => 'Acme Bakery',
        ], $attributes));
    }

    /**
     * A designed template whose content carries a heading with a placeholder, rich text with a link,
     * and a button — so one send exercises headings, tokens, links and CTAs together.
     *
     * `body` is stored exactly as the application stores it: the design's plain-text form with the
     * placeholders left in, which is what the composer is later handed with them filled in.
     */
    private function designedTemplate(array $contentOverride = [], ?array $headerBlocks = null): EmailTemplate
    {
        $design = EmailDesign::create([
            'name' => 'Brand design',
            'is_default' => true,
            'layout' => $this->layout($headerBlocks, null, styles: [
                'font' => 'georgia',
                'background_color' => '#101828',
                'content_background_color' => '#fffdf5',
                'text_color' => '#1a1a2e',
                'muted_text_color' => '#6b7280',
                'link_color' => '#b91c1c',
                'accent_color' => '#047857',
                'accent_text_color' => '#f0fdf4',
                'divider_color' => '#d1d5db',
            ]),
        ]);

        // Every block here DROPS its own colour so the design's styles are what render. A block that
        // sets `color` or `background_color` correctly overrides the design (BLOCK_COLOR_FALLBACKS),
        // so a fixture that kept the trait's defaults would prove the opposite of what we want.
        $inherit = static fn (array $block, string ...$keys): array => array_diff_key($block, array_flip($keys));

        $content = $contentOverride !== [] ? $contentOverride : $this->content([
            $inherit($this->heading(['text' => 'Hello {{first_name}}', 'level' => 1]), 'color'),
            $inherit($this->textBlock(['content' => $this->doc($this->para(
                $this->text('We would love to help '),
                $this->text('{{business_name}}', [['type' => 'link', 'attrs' => ['href' => 'https://viteseo.test/offer']]]),
                $this->text(' grow.'),
            ))]), 'color'),
            $inherit($this->button(['label' => 'Book a call', 'url' => 'https://viteseo.test/book']), 'background_color', 'text_color'),
            $inherit($this->divider(), 'color'),
        ]);

        /** @var EmailTemplateDesignService $rendering */
        $rendering = app(EmailTemplateDesignService::class);

        return EmailTemplate::create([
            'purpose' => 'Introduction',
            'tone' => 'Warm & professional',
            'subject' => 'A quick idea for {{business_name}}',
            'body' => $rendering->plainTextBody($design->layout, $content),
            'content' => $content,
            'email_design_id' => $design->id,
            'is_active' => true,
        ]);
    }

    /** What the composer is handed for this lead — the exact string a BD sees and may edit. */
    private function asOffered(EmailTemplate $template, Lead $lead, User $sender): string
    {
        return app(TemplateRenderer::class)->render($template->subject, $template->body, $lead, $sender)['body'];
    }

    private function send(Lead $lead, array $payload)
    {
        return $this->postJson("/api/v1/crm/leads/{$lead->id}/emails", $payload);
    }

    private function lastSent(): MimeEmail
    {
        $message = Mail::mailer()->getSymfonyTransport()->messages()->last()?->getOriginalMessage();
        $this->assertInstanceOf(MimeEmail::class, $message, 'Expected an email to have been sent.');

        return $message;
    }

    // ── THE CENTRAL CLAIM ────────────────────────────────────────────────────────────────────

    /**
     * The preview and the send are the same bytes.
     *
     * Rendered through the two public entry points a person actually reaches — the Settings preview
     * and an HTTP send — with the same design, template, lead and tokens. Byte equality is the only
     * assertion strong enough to mean "what you previewed is what they receive"; anything looser
     * would pass while a second renderer quietly drifted.
     */
    public function test_the_preview_html_and_the_sent_html_are_identical(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $template = $this->designedTemplate();

        $this->send($lead, [
            'subject' => 'A quick idea',
            'body' => $this->asOffered($template, $lead, $sender),
            'email_template_id' => (string) $template->id,
        ])->assertCreated();

        $sentHtml = (string) $this->lastSent()->getHtmlBody();

        // The preview, rendered with the SAME context the send used.
        $previewHtml = app(EmailDesignRenderer::class)->toHtml(
            $template->design->layout,
            $template->content,
            app(EmailTemplateDesignService::class)->sendContext($lead, $sender),
        );

        $this->assertSame($previewHtml, $sentHtml, 'The previewed HTML and the sent HTML must be identical.');
    }

    /** Every design property the product promises survives into the message on the wire. */
    public function test_the_sent_html_carries_the_designs_styles_and_the_templates_content(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $template = $this->designedTemplate();

        $this->send($lead, [
            'subject' => 'A quick idea',
            'body' => $this->asOffered($template, $lead, $sender),
            'email_template_id' => (string) $template->id,
        ])->assertCreated();

        $html = (string) $this->lastSent()->getHtmlBody();

        foreach ([
            '#101828' => 'page background',
            '#fffdf5' => 'email background',
            '#1a1a2e' => 'text color',
            '#b91c1c' => 'link color',
            '#047857' => 'button color',
            '#f0fdf4' => 'button text color',
            '#d1d5db' => 'divider color',
            // The renderer emits the FAMILY the key maps to, not the key — FONTS['georgia'].
            'Georgia, Times New Roman' => 'font',
        ] as $needle => $what) {
            $this->assertStringContainsString($needle, $html, "The sent HTML lost the design's {$what}.");
        }

        // Template content: heading, link and button all reach the wire.
        $this->assertStringContainsString('Hello Maria', $html, 'The heading is missing.');
        $this->assertStringContainsString('https://viteseo.test/offer', $html, 'The text link is missing.');
        $this->assertStringContainsString('https://viteseo.test/book', $html, 'The button URL is missing.');
        $this->assertStringContainsString('Book a call', $html, 'The button label is missing.');
    }

    // ── The design-level button ──────────────────────────────────────────────────────────────

    /**
     * A button placed in the DESIGN's header is the reusable call to action every template drawn in
     * that design carries — and it must survive to the recipient exactly as the preview showed it.
     *
     * Asserted with byte equality for the same reason the content test is: anything looser would
     * still pass if a second rendering path appeared.
     */
    public function test_a_design_level_button_renders_identically_in_the_preview_and_the_send(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();

        $template = $this->designedTemplate(headerBlocks: [
            $this->logo(),
            $this->button(['id' => 'design-cta', 'label' => 'View our work', 'url' => 'https://viteseo.test/work']),
        ]);

        $this->send($lead, [
            'subject' => 'A quick idea',
            'body' => $this->asOffered($template, $lead, $sender),
            'email_template_id' => (string) $template->id,
        ])->assertCreated();

        $sentHtml = (string) $this->lastSent()->getHtmlBody();

        $previewHtml = app(EmailDesignRenderer::class)->toHtml(
            $template->design->layout,
            $template->content,
            app(EmailTemplateDesignService::class)->sendContext($lead, $sender),
        );

        $this->assertSame($previewHtml, $sentHtml, 'A design-level button must render identically in both.');
    }

    /** Its URL arrives as a real anchor a recipient can click, not as bare text. */
    public function test_a_design_level_buttons_url_is_a_clickable_link_in_the_sent_email(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();

        $template = $this->designedTemplate(headerBlocks: [
            $this->button(['id' => 'design-cta', 'label' => 'View our work', 'url' => 'https://viteseo.test/work']),
        ]);

        $this->send($lead, [
            'subject' => 'A quick idea',
            'body' => $this->asOffered($template, $lead, $sender),
            'email_template_id' => (string) $template->id,
        ])->assertCreated();

        $message = $this->lastSent();
        $html = (string) $message->getHtmlBody();

        $this->assertStringContainsString('<a href="https://viteseo.test/work"', $html, 'The design button is not a link.');
        $this->assertStringContainsString('View our work', $html);
        // A text-only client still gets the destination.
        $this->assertStringContainsString('https://viteseo.test/work', (string) $message->getTextBody());
    }

    /**
     * THE DESIGN'S BUTTON AND THE TEMPLATE'S OWN COEXIST. Adding the reusable one must not displace
     * the per-template call to action, which is a different block owned by a different document.
     */
    public function test_a_design_button_and_a_template_button_both_reach_the_recipient(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();

        $template = $this->designedTemplate(headerBlocks: [
            $this->button(['id' => 'design-cta', 'label' => 'View our work', 'url' => 'https://viteseo.test/work']),
        ]);

        $this->send($lead, [
            'subject' => 'A quick idea',
            'body' => $this->asOffered($template, $lead, $sender),
            'email_template_id' => (string) $template->id,
        ])->assertCreated();

        $html = (string) $this->lastSent()->getHtmlBody();

        $this->assertStringContainsString('https://viteseo.test/work', $html, 'The design button is missing.');
        $this->assertStringContainsString('https://viteseo.test/book', $html, 'The template button is missing.');
    }

    /**
     * BACKWARD COMPATIBILITY. A design saved before buttons were allowed in the header — one with no
     * button at all — renders exactly as it always did. Widening an allow-list cannot change what an
     * existing document produces, and this is the assertion that says so.
     */
    public function test_a_design_without_any_button_is_unaffected(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();

        $template = $this->designedTemplate(headerBlocks: [$this->logo()]);

        $this->send($lead, [
            'subject' => 'A quick idea',
            'body' => $this->asOffered($template, $lead, $sender),
            'email_template_id' => (string) $template->id,
        ])->assertCreated();

        $sentHtml = (string) $this->lastSent()->getHtmlBody();
        $previewHtml = app(EmailDesignRenderer::class)->toHtml(
            $template->design->layout,
            $template->content,
            app(EmailTemplateDesignService::class)->sendContext($lead, $sender),
        );

        $this->assertSame($previewHtml, $sentHtml);
        $this->assertStringNotContainsString('https://viteseo.test/work', $sentHtml, 'No design button was placed.');
        // The template's own button is untouched by any of this.
        $this->assertStringContainsString('https://viteseo.test/book', $sentHtml);
    }

    // ── 1. Multipart ─────────────────────────────────────────────────────────────────────────

    public function test_a_designed_send_is_multipart_with_a_matching_plain_text_part(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $template = $this->designedTemplate();
        $offered = $this->asOffered($template, $lead, $sender);

        $this->send($lead, ['subject' => 'A quick idea', 'body' => $offered, 'email_template_id' => (string) $template->id])
            ->assertCreated();

        $message = $this->lastSent();
        $raw = $message->toString();

        $this->assertStringContainsString('multipart/alternative', $raw);
        $this->assertStringContainsString('text/plain', $raw);
        $this->assertStringContainsString('text/html', $raw);

        // The text part is the plain-text body the row carries — a text-only client gets a readable email.
        $this->assertSame($offered, (string) $message->getTextBody());
        $this->assertNotSame('', (string) $message->getHtmlBody());
    }

    // ── 2. Nothing changes without a template id ─────────────────────────────────────────────

    public function test_a_send_without_a_template_id_is_plain_text_exactly_as_before(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = $this->lead();

        $this->send($lead, ['subject' => 'Hello', 'body' => 'Just a plain note.'])->assertCreated();

        $message = $this->lastSent();
        $this->assertNull($message->getHtmlBody(), 'A plain send must carry no HTML part.');
        $this->assertSame('Just a plain note.', (string) $message->getTextBody());
        $this->assertNull(Email::query()->latest('id')->first()->email_template_id);
    }

    // ── 3. body_doc still wins ───────────────────────────────────────────────────────────────

    public function test_a_formatted_reply_wins_over_a_designed_template(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $template = $this->designedTemplate();

        $this->send($lead, [
            'subject' => 'Re: Proposal',
            'body' => 'Hi Maria',
            'body_doc' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hi Maria']]]]],
            'email_template_id' => (string) $template->id,
        ])->assertCreated();

        $html = (string) $this->lastSent()->getHtmlBody();

        $this->assertStringContainsString('Hi Maria', $html, 'The formatted reply should have produced the HTML.');
        $this->assertStringNotContainsString('<table', $html, 'The design renderer produced this, not EmailDocument.');
        $this->assertStringNotContainsString('Book a call', $html, 'The design must not have been rendered.');
    }

    // ── 4-5. Broken or missing sources fall back ─────────────────────────────────────────────

    public function test_an_invalid_stored_design_falls_back_to_plain_text(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $template = $this->designedTemplate();
        $offered = $this->asOffered($template, $lead, $sender);

        // Corrupt the stored layout the way a hand-edit or a bad migration would.
        $template->design->forceFill(['layout' => ['version' => 1, 'header' => 'not-a-list']])->save();

        $this->send($lead, ['subject' => 'A quick idea', 'body' => $offered, 'email_template_id' => (string) $template->id])
            ->assertCreated();

        $this->assertNull($this->lastSent()->getHtmlBody(), 'An invalid design must never be rendered best-effort.');
    }

    public function test_a_deleted_template_falls_back_to_plain_text(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $template = $this->designedTemplate();
        $offered = $this->asOffered($template, $lead, $sender);
        $templateId = (string) $template->id;

        $template->forceDelete();

        $this->send($lead, ['subject' => 'A quick idea', 'body' => $offered, 'email_template_id' => $templateId])
            ->assertStatus(422); // `exists` refuses it at the request boundary — before anything is sent.

        // And a row whose template disappears after the fact still sends, as plain text.
        $email = Email::create([
            'lead_id' => $lead->id, 'user_id' => $sender->id, 'direction' => 'outbound',
            'from_email' => 'crm@viteseo.test', 'from_name' => 'CRM',
            'to_email' => (string) $lead->email, 'subject' => 'A quick idea',
            'body' => $offered, 'email_template_id' => null, 'status' => 'draft',
        ]);
        app(OutreachEmailService::class)->sendExisting($email, $sender);

        $this->assertNull($this->lastSent()->getHtmlBody());
    }

    // ── 6. THE EDIT FENCE ────────────────────────────────────────────────────────────────────

    /**
     * A BD who edits the composer text must never have the ORIGINAL blocks mailed out behind it.
     * Asserted server-side with the id still present, because the guarantee cannot depend on the
     * browser having dropped it.
     */
    public function test_an_edited_body_never_sends_the_stale_designed_html(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $template = $this->designedTemplate();

        $this->send($lead, [
            'subject' => 'A quick idea',
            'body' => "Hi Maria,\n\nActually, let's talk about something completely different.",
            'email_template_id' => (string) $template->id, // still sent — the server must refuse it
        ])->assertCreated();

        $message = $this->lastSent();

        $this->assertNull($message->getHtmlBody(), 'An edited body must not send the template design.');
        $this->assertStringContainsString('completely different', (string) $message->getTextBody());
        $this->assertStringNotContainsString('Book a call', $message->toString(), 'Stale designed content leaked.');
    }

    // ── 7-8. Tokens and preview-only data ────────────────────────────────────────────────────

    public function test_real_lead_tokens_are_substituted_in_the_sent_html(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead(['contact_name' => 'Jose Rizal', 'business_name' => 'Noli Press']);
        $template = $this->designedTemplate();

        $this->send($lead, [
            'subject' => 'A quick idea',
            'body' => $this->asOffered($template, $lead, $sender),
            'email_template_id' => (string) $template->id,
        ])->assertCreated();

        $html = (string) $this->lastSent()->getHtmlBody();

        $this->assertStringContainsString('Hello Jose', $html);
        $this->assertStringContainsString('Noli Press', $html);
        $this->assertStringNotContainsString('{{', $html, 'An unsubstituted placeholder reached the recipient.');
    }

    public function test_the_sent_html_never_contains_preview_only_data_uris(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $template = $this->designedTemplate();

        $this->send($lead, [
            'subject' => 'A quick idea',
            'body' => $this->asOffered($template, $lead, $sender),
            'email_template_id' => (string) $template->id,
        ])->assertCreated();

        $this->assertStringNotContainsString('data:image', (string) $this->lastSent()->getHtmlBody());
    }

    // ── 9. The Gmail guard, with this lead's real values ─────────────────────────────────────

    public function test_html_over_the_size_guard_falls_back_to_plain_text(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();

        // Small stored, huge rendered: an ampersand is one byte of JSON and five of escaped HTML
        // (`&amp;`). That clears the schema's 100 KB document limit while comfortably exceeding the
        // 90 KB Gmail guard — which is the only way to exercise the guard without an invalid document.
        // A text block is capped at MAX_BLOCK_TEXT (5,000 chars), so the volume comes from several
        // blocks rather than one huge one. 5 x 4,900 ampersands is ~24.5 KB of JSON — comfortably
        // inside the 100 KB document limit — and ~122 KB once escaped, well past the 90 KB guard.
        $expanding = str_repeat('&', 4900);
        $template = $this->designedTemplate($this->content(array_map(
            fn (int $i): array => $this->textBlock(['id' => "bulk-{$i}", 'content' => $this->doc($this->para($this->text($expanding)))]),
            range(1, 5),
        )));

        $this->send($lead, [
            'subject' => 'A quick idea',
            'body' => $this->asOffered($template, $lead, $sender),
            'email_template_id' => (string) $template->id,
        ])->assertCreated();

        $this->assertNull(
            $this->lastSent()->getHtmlBody(),
            'An email over the Gmail clipping guard must be sent as plain text, not clipped HTML.',
        );
    }

    // ── 10. THE SENT SNAPSHOT (crm_emails.body_html) ─────────────────────────────────────────
    //
    // Lead Details shows the sender what the recipient received. The only exact record of that is the
    // HTML string the transport was handed, so it is stored at send time and never re-rendered.

    /** A designed send as the composer makes it. */
    private function sendDesigned(Lead $lead, User $sender, EmailTemplate $template)
    {
        return $this->send($lead, [
            'subject' => 'A quick idea',
            'body' => $this->asOffered($template, $lead, $sender),
            'email_template_id' => (string) $template->id,
        ])->assertCreated();
    }

    /** The branding logo every render resolves, without needing a stored asset. */
    private function brandLogo(string $url): void
    {
        $this->mock(EmailBrandingService::class, function ($mock) use ($url): void {
            $mock->shouldReceive('logoForRender')->andReturn(['url' => $url, 'png' => 'png-bytes', 'alt' => 'Acme']);
        });
    }

    private function thread(Lead $lead): array
    {
        return $this->getJson("/api/v1/crm/leads/{$lead->id}/emails")->assertOk()->json('data');
    }

    public function test_a_successful_designed_send_stores_exactly_the_html_that_was_sent(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $template = $this->designedTemplate();

        $this->sendDesigned($lead, $sender, $template);

        $sentHtml = (string) $this->lastSent()->getHtmlBody();
        $this->assertStringContainsString('Book a call', $sentHtml, 'The provider must have received the design.');
        $this->assertSame($sentHtml, Email::query()->latest('id')->first()->body_html);
    }

    public function test_the_lead_thread_returns_the_stored_html(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $template = $this->designedTemplate();

        $this->sendDesigned($lead, $sender, $template);
        $sentHtml = (string) $this->lastSent()->getHtmlBody();

        $message = collect($this->thread($lead))->firstWhere('direction', 'outbound');

        $this->assertSame($sentHtml, $message['bodyHtml']);
        $this->assertNull($message['bodyDoc'], 'A designed send is not a formatted reply.');
        // The plain-text part is still there for the text/plain view and every other consumer.
        $this->assertStringContainsString('Book a call', $message['body']);
    }

    /**
     * The snapshot is history, not a render: every input to the design can change after the send, and
     * none of it may reach what Lead Details shows for that message.
     */
    public function test_the_stored_html_survives_every_later_change_to_its_inputs(): void
    {
        $this->brandLogo('https://crm.viteseo.test/api/v1/crm/email-assets/aaaaaaaa-1111-4111-8111-111111111111.png');
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $template = $this->designedTemplate();

        $this->sendDesigned($lead, $sender, $template);
        $sentHtml = (string) $this->lastSent()->getHtmlBody();
        $this->assertStringContainsString('aaaaaaaa-1111', $sentHtml, 'The logo must have been part of the send.');

        // Change everything the renderer reads.
        $template->forceFill(['content' => $this->content([$this->heading(['text' => 'A completely new heading'])])])->save();
        $template->design->forceFill(['layout' => $this->layout(styles: ['background_color' => '#ff0000'])])->save();
        $lead->forceFill(['business_name' => 'Renamed Bakery', 'contact_name' => 'Someone Else'])->save();
        $sender->forceFill(['name' => 'Renamed Sender'])->save();
        $this->brandLogo('https://crm.viteseo.test/api/v1/crm/email-assets/bbbbbbbb-2222-4222-8222-222222222222.png');

        $message = collect($this->thread($lead))->firstWhere('direction', 'outbound');

        $this->assertSame($sentHtml, $message['bodyHtml'], 'Lead Details must show what was sent, not a re-render.');
        $this->assertStringNotContainsString('A completely new heading', $message['bodyHtml']);
        $this->assertStringNotContainsString('bbbbbbbb-2222', $message['bodyHtml']);
        $this->assertStringContainsString('Acme Bakery', $message['bodyHtml']);
    }

    public function test_a_soft_deleted_template_does_not_lose_the_stored_html(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $template = $this->designedTemplate();

        $this->sendDesigned($lead, $sender, $template);
        $sentHtml = (string) $this->lastSent()->getHtmlBody();
        $template->delete();

        $this->assertSame($sentHtml, collect($this->thread($lead))->firstWhere('direction', 'outbound')['bodyHtml']);
    }

    public function test_a_plain_text_send_stores_no_html(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = $this->lead();

        $this->send($lead, ['subject' => 'Hello', 'body' => 'Just a plain note.'])->assertCreated();

        $this->assertNull($this->lastSent()->getHtmlBody());
        $this->assertNull(Email::query()->latest('id')->first()->body_html);
        $this->assertNull(collect($this->thread($lead))->firstWhere('direction', 'outbound')['bodyHtml']);
    }

    public function test_an_edited_designed_body_sends_plain_text_and_stores_no_html(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $template = $this->designedTemplate();

        $this->send($lead, [
            'subject' => 'A quick idea',
            'body' => "Hi Maria,\n\nI rewrote this one myself.",
            'email_template_id' => (string) $template->id,
        ])->assertCreated();

        $this->assertNull($this->lastSent()->getHtmlBody());
        $email = Email::query()->latest('id')->first();
        $this->assertNull($email->body_html);
        $this->assertSame((string) $template->id, (string) $email->email_template_id, 'The id is recorded unchanged; only the render is refused.');
    }

    /**
     * A formatted reply keeps rendering from its document in the thread (bodyDoc wins on the frontend);
     * the snapshot is simply the HTML it was sent with, which is still EmailDocument's.
     */
    public function test_a_formatted_reply_keeps_its_document_and_stores_the_html_it_was_sent_with(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = $this->lead();
        $doc = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Hi '],
            ['type' => 'text', 'text' => 'Maria', 'marks' => [['type' => 'bold']]],
        ]]]];

        $this->send($lead, ['subject' => 'Re: Proposal', 'body' => 'Hi Maria', 'body_doc' => $doc])->assertCreated();

        $sentHtml = (string) $this->lastSent()->getHtmlBody();
        $this->assertSame(EmailDocument::toHtml($doc), $sentHtml);

        $message = collect($this->thread($lead))->firstWhere('direction', 'outbound');
        $this->assertSame($doc, $message['bodyDoc']);
        $this->assertSame('Hi Maria', $message['body']);
        $this->assertSame($sentHtml, $message['bodyHtml']);
    }

    public function test_real_lead_tokens_are_substituted_in_the_stored_html(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead(['contact_name' => 'Jose Rizal', 'business_name' => 'Noli Press']);
        $template = $this->designedTemplate();

        $this->sendDesigned($lead, $sender, $template);

        $stored = (string) Email::query()->latest('id')->first()->body_html;
        $this->assertStringContainsString('Hello Jose', $stored);
        $this->assertStringContainsString('Noli Press', $stored);
        $this->assertStringNotContainsString('{{', $stored);
    }

    /** Markup in lead data or template text reaches the snapshot escaped, never as live markup. */
    public function test_markup_in_tokens_and_template_text_is_stored_escaped(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead([
            'contact_name' => '<img src=x onerror=alert(1)> Maria',
            'business_name' => '"><script>alert(1)</script>',
        ]);
        $template = $this->designedTemplate($this->content([
            $this->heading(['text' => 'Hi {{first_name}} <script>alert(2)</script>']),
            $this->textBlock(['content' => $this->doc($this->para($this->text('For {{business_name}} <svg onload=alert(3)>')))]),
        ]));

        $this->sendDesigned($lead, $sender, $template);
        $stored = (string) Email::query()->latest('id')->first()->body_html;

        $this->assertNotSame('', $stored, 'The design must have been sent for this to test anything.');
        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('<img src=x', $stored);
        $this->assertStringNotContainsString('<svg', $stored);
        $this->assertDoesNotMatchRegularExpression('/<[^>]+\son(error|load)\s*=/i', $stored, 'An event handler survived as markup.');
        $this->assertStringContainsString('&lt;script&gt;', $stored);
    }

    /** A stored design with an unsafe link is never rendered, so nothing unsafe can be stored either. */
    public function test_an_unsafe_link_in_a_stored_design_sends_plain_text_and_stores_no_html(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $template = $this->designedTemplate();

        // Corrupted at rest (a hand-edit or a bad migration) — the save path would have refused it.
        $content = $template->content;
        $content['blocks'][] = $this->button(['id' => 'evil', 'label' => 'Click', 'url' => 'javascript:alert(1)']);
        $template->forceFill(['content' => $content])->save();

        $this->sendDesigned($lead, $sender, $template);

        $this->assertNull($this->lastSent()->getHtmlBody());
        $this->assertNull(Email::query()->latest('id')->first()->body_html);
        $this->assertStringNotContainsString('javascript:', $this->lastSent()->toString());
    }

    public function test_a_failed_send_stores_no_html(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $template = $this->designedTemplate();

        $this->app->instance(EmailProvider::class, new class implements EmailProvider
        {
            public function send(EmailMessage $message): SentEmail
            {
                throw new \RuntimeException('SMTP down');
            }
        });

        $this->send($lead, [
            'subject' => 'A quick idea',
            'body' => $this->asOffered($template, $lead, $sender),
            'email_template_id' => (string) $template->id,
        ])->assertStatus(500);

        $email = Email::query()->latest('id')->first();
        $this->assertSame('failed', $email->status);
        $this->assertNull($email->body_html, 'A message that never left must not read as a delivered design.');
    }

    public function test_a_provider_reporting_failure_stores_no_html(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $template = $this->designedTemplate();

        $this->app->instance(EmailProvider::class, new class implements EmailProvider
        {
            public function send(EmailMessage $message): SentEmail
            {
                return new SentEmail(provider: 'fake', status: 'failed');
            }
        });

        $this->sendDesigned($lead, $sender, $template);

        $email = Email::query()->latest('id')->first();
        $this->assertSame('failed', $email->status);
        $this->assertNull($email->body_html);
    }

    /** Drafts and scheduled sends reach the same pipeline, and so the same snapshot rule. */
    public function test_drafts_and_scheduled_sends_store_the_html_they_were_sent_with(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();

        // A draft is plain text: sent through /send, it stores no HTML.
        $draftId = $this->postJson("/api/v1/crm/leads/{$lead->id}/emails/drafts", ['subject' => 'Draft', 'body' => 'Plain draft'])
            ->assertCreated()->json('data.id');
        $this->postJson("/api/v1/crm/emails/{$draftId}/send")->assertOk();
        $this->assertNull($this->lastSent()->getHtmlBody());
        $this->assertNull(Email::query()->findOrFail($draftId)->body_html);

        // A scheduled formatted reply, sent now: the HTML rendered at that moment is what is stored.
        $doc = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Later, formatted']]]]];
        $scheduledId = $this->send($lead, [
            'subject' => 'Scheduled',
            'body' => 'Later, formatted',
            'body_doc' => $doc,
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])->assertCreated()->json('data.id');
        $this->assertNull(Email::query()->findOrFail($scheduledId)->body_html, 'Nothing is stored before the send.');

        $this->postJson("/api/v1/crm/emails/{$scheduledId}/send-now")->assertOk();
        $sentHtml = (string) $this->lastSent()->getHtmlBody();
        $this->assertSame(EmailDocument::toHtml($doc), $sentHtml);
        $this->assertSame($sentHtml, Email::query()->findOrFail($scheduledId)->body_html);
    }

    /** The snapshot travels only to the Lead Details thread — never the drafts list or send responses. */
    public function test_only_the_lead_thread_exposes_body_html(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $template = $this->designedTemplate();

        $sendResponse = $this->sendDesigned($lead, $sender, $template);
        $this->assertArrayNotHasKey('bodyHtml', $sendResponse->json('data'));

        $this->postJson("/api/v1/crm/leads/{$lead->id}/emails/drafts", ['subject' => 'Draft', 'body' => 'x'])->assertCreated();
        $drafts = $this->getJson('/api/v1/crm/emails?status=draft')->assertOk()->json('data');
        $this->assertNotEmpty($drafts);
        foreach ($drafts as $draft) {
            $this->assertArrayNotHasKey('bodyHtml', $draft);
        }

        $this->assertArrayHasKey('bodyHtml', $this->thread($lead)[0]);
    }

    /** A request can never write the snapshot: no field accepts markup. */
    public function test_a_client_supplied_body_html_is_ignored(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = $this->lead();

        $this->send($lead, [
            'subject' => 'Hello',
            'body' => 'Plain note',
            'body_html' => '<script>alert(1)</script>',
        ])->assertCreated();

        $this->assertNull(Email::query()->latest('id')->first()->body_html);
        $this->assertNull($this->lastSent()->getHtmlBody());
    }

    // ── 11. COMPOSER FEED: the template's own design, reported ──────────────────────────────
    //
    // The design is a property of the template (Settings), never chosen per send. The composer feed only
    // reports it, so the composer can say "Designed · {name}" or "Plain text".

    /** @return array<string, mixed> the feed entry for a template */
    private function feedEntry(Lead $lead, EmailTemplate $template): array
    {
        $entry = collect($this->getJson("/api/v1/crm/leads/{$lead->id}/email-templates")->assertOk()->json('data'))
            ->firstWhere('id', (string) $template->id);
        $this->assertIsArray($entry, 'The template should be in the composer feed.');

        return $entry;
    }

    public function test_the_composer_feed_reports_a_designed_templates_design(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $template = $this->designedTemplate();

        $entry = $this->feedEntry($lead, $template);

        $this->assertTrue($entry['isDesigned']);
        $this->assertSame('Brand design', $entry['designName']);
        // The existing fields are unchanged: subject and the lead-rendered plain-text body.
        $this->assertSame($this->asOffered($template, $lead, $sender), $entry['body']);
        $this->assertSame('A quick idea for Acme Bakery', $entry['subject']);
        $this->assertSame(['id', 'purpose', 'tone', 'subject', 'body', 'isDesigned', 'designName'], array_keys($entry));
    }

    public function test_the_composer_feed_reports_a_plain_template_as_plain(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = $this->lead();
        $plain = EmailTemplate::create([
            'purpose' => 'Follow-up', 'tone' => 'Casual', 'subject' => 'Checking in',
            'body' => 'Hi {{first_name}}, just checking in.', 'is_active' => true,
        ]);

        $entry = $this->feedEntry($lead, $plain);

        $this->assertFalse($entry['isDesigned']);
        $this->assertNull($entry['designName']);
        $this->assertSame('Hi Maria, just checking in.', $entry['body']);
    }

    public function test_the_composer_feed_never_shows_another_organizations_template_or_design(): void
    {
        $theirOrg = app(OrgOnboardingService::class)->createOrg(
            'Org theirs', 'Owner theirs', 'owner-theirs@client.ph', 'password-123', ['crm' => 'full'],
        )['org'];
        CurrentOrg::runAs($theirOrg->id, function (): void {
            $design = EmailDesign::create(['name' => 'Their secret design', 'is_default' => false, 'layout' => $this->layout()]);
            EmailTemplate::create([
                'purpose' => 'Follow-up', 'tone' => 'Their tone', 'subject' => 'Theirs', 'body' => 'Theirs',
                'content' => $this->content([$this->heading(['text' => 'Theirs'])]),
                'email_design_id' => $design->id, 'is_active' => true,
            ]);
        });
        CurrentOrg::clear();

        Sanctum::actingAs($this->admin());
        $lead = $this->lead();
        $feed = $this->getJson("/api/v1/crm/leads/{$lead->id}/email-templates")->assertOk()->json('data');

        $this->assertNotContains('Their tone', array_column($feed, 'tone'));
        $this->assertNotContains('Their secret design', array_column($feed, 'designName'));
    }

    public function test_the_composer_feed_keeps_its_existing_rbac(): void
    {
        $bd = User::factory()->create(['status' => 'active']);
        $bd->assignRole('crm_bd');
        $own = $this->lead(['assigned_to' => $bd->id]);
        $other = $this->lead(['assigned_to' => $this->admin()->id, 'email' => 'other@acme.test']);
        $template = $this->designedTemplate();

        Sanctum::actingAs($bd);
        $this->assertTrue($this->feedEntry($own, $template)['isDesigned']);
        $this->getJson("/api/v1/crm/leads/{$other->id}/email-templates")->assertForbidden();
        // Design management stays admin-only: the composer feed is not a way into it.
        $this->getJson('/api/v1/crm/settings/email-designs')->assertForbidden();
    }

    // ── 12. SCHEDULED DESIGNED SENDS ─────────────────────────────────────────────────────────
    //
    // A scheduled email used to drop its template id, so a designed template scheduled for later went
    // out as plain text. It is now recorded and rendered at delivery by the same sendExisting() path.

    private function scheduleDesigned(Lead $lead, string $body, EmailTemplate $template): Email
    {
        $id = $this->send($lead, [
            'subject' => 'A quick idea',
            'body' => $body,
            'email_template_id' => (string) $template->id,
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])->assertCreated()->assertJsonPath('data.status', 'scheduled')->json('data.id');

        return Email::query()->findOrFail($id);
    }

    private function deliverDue(): void
    {
        $this->travel(2)->days();
        SendScheduledEmailsJob::dispatchSync();
    }

    public function test_a_scheduled_designed_email_is_delivered_in_its_design(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $template = $this->designedTemplate();

        $email = $this->scheduleDesigned($lead, $this->asOffered($template, $lead, $sender), $template);

        $this->assertSame($template->id, $email->email_template_id, 'Scheduling must keep the template id.');
        $this->assertNull($email->body_html, 'Nothing is rendered or stored before delivery.');
        $this->assertCount(0, Mail::mailer()->getSymfonyTransport()->messages(), 'Scheduling must not send.');

        $this->deliverDue();

        $sentHtml = (string) $this->lastSent()->getHtmlBody();
        $expected = app(EmailDesignRenderer::class)->toHtml(
            $template->design->layout,
            $template->content,
            app(EmailTemplateDesignService::class)->sendContext($lead, $sender),
        );

        $this->assertSame($expected, $sentHtml, 'Delivery must use the existing design renderer and send context.');
        $this->assertStringContainsString('Book a call', $sentHtml);
        $delivered = Email::withoutGlobalScopes()->findOrFail($email->id);
        $this->assertSame('sent', $delivered->status);
        $this->assertSame($sentHtml, $delivered->body_html, 'The snapshot is byte-identical to what the transport received.');
        $this->assertSame((string) $delivered->body, (string) $this->lastSent()->getTextBody(), 'The plain-text part is still the stored body.');
    }

    public function test_send_now_on_a_scheduled_designed_email_sends_its_design(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $template = $this->designedTemplate();

        $email = $this->scheduleDesigned($lead, $this->asOffered($template, $lead, $sender), $template);
        $this->postJson("/api/v1/crm/emails/{$email->id}/send-now")->assertOk();

        $sentHtml = (string) $this->lastSent()->getHtmlBody();
        $this->assertStringContainsString('Book a call', $sentHtml);
        $this->assertSame($sentHtml, $email->fresh()->body_html);
    }

    public function test_a_scheduled_email_with_an_edited_body_is_delivered_as_plain_text(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $template = $this->designedTemplate();

        $email = $this->scheduleDesigned($lead, "Hi Maria,\n\nI rewrote this myself.", $template);
        $this->assertSame($template->id, $email->email_template_id, 'The id is recorded; the edit check decides at delivery.');

        $this->deliverDue();

        $message = $this->lastSent();
        $this->assertNull($message->getHtmlBody(), 'The existing edit check must still refuse the design.');
        $this->assertStringContainsString('I rewrote this myself.', (string) $message->getTextBody());
        $this->assertNull(Email::withoutGlobalScopes()->findOrFail($email->id)->body_html);
    }

    public function test_a_template_edited_while_scheduled_falls_back_to_plain_text(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $template = $this->designedTemplate();

        $email = $this->scheduleDesigned($lead, $this->asOffered($template, $lead, $sender), $template);

        // An admin rewrites the template before the email is due: its derived body no longer matches what
        // the BD scheduled, so the edit check refuses the design — the BD's scheduled text goes out plain.
        $content = $this->content([$this->heading(['text' => 'A different message entirely'])]);
        $template->forceFill([
            'content' => $content,
            'body' => app(EmailTemplateDesignService::class)->plainTextBody($template->design->layout, $content),
        ])->save();

        $this->deliverDue();

        $this->assertNull($this->lastSent()->getHtmlBody());
        $this->assertStringNotContainsString('A different message entirely', $this->lastSent()->toString());
        $this->assertNull(Email::withoutGlobalScopes()->findOrFail($email->id)->body_html);
    }

    public function test_a_scheduled_plain_email_is_unchanged(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = $this->lead();

        $id = $this->send($lead, [
            'subject' => 'Later', 'body' => 'Plain later', 'scheduled_at' => now()->addDay()->toIso8601String(),
        ])->assertCreated()->json('data.id');
        $this->assertNull(Email::query()->findOrFail($id)->email_template_id);

        $this->deliverDue();

        $this->assertNull($this->lastSent()->getHtmlBody());
        $this->assertNull(Email::withoutGlobalScopes()->findOrFail($id)->body_html);
    }

    /** Delivering a later email never touches an earlier email's snapshot, even after a template edit. */
    public function test_a_scheduled_delivery_never_rewrites_an_earlier_snapshot(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $template = $this->designedTemplate();

        $this->sendDesigned($lead, $sender, $template);
        $first = Email::query()->latest('id')->firstOrFail();
        $firstHtml = (string) $first->body_html;
        $this->assertNotSame('', $firstHtml);

        $template->design->forceFill(['layout' => $this->layout(styles: ['background_color' => '#123456'])])->save();
        $this->scheduleDesigned($lead, $this->asOffered($template, $lead, $sender), $template);
        $this->deliverDue();

        $this->assertStringContainsString('#123456', (string) $this->lastSent()->getHtmlBody(), 'The later send uses the edited design.');
        $this->assertSame($firstHtml, Email::withoutGlobalScopes()->findOrFail($first->id)->body_html, 'The earlier snapshot is untouched.');
    }

    // ── 13. DRAFTS KEEP THEIR TEMPLATE ───────────────────────────────────────────────────────
    //
    // A draft composed from a template records it, so sending the draft later renders that template's
    // design through the same sendExisting() path, edit check included. Nothing about the design is stored
    // on the draft: only the id, scoped to this organization's live templates.

    /** @param array<string, mixed> $payload */
    private function saveDraft(Lead $lead, array $payload)
    {
        return $this->postJson("/api/v1/crm/leads/{$lead->id}/emails/drafts", $payload);
    }

    private function draftFrom(Lead $lead, string $body, ?EmailTemplate $template): Email
    {
        $id = $this->saveDraft($lead, [
            'subject' => 'A quick idea',
            'body' => $body,
            'email_template_id' => $template !== null ? (string) $template->id : null,
        ])->assertCreated()->json('data.id');

        return Email::query()->findOrFail($id);
    }

    private function plainTemplate(): EmailTemplate
    {
        return EmailTemplate::create([
            'purpose' => 'Follow-up', 'tone' => 'Casual', 'subject' => 'Checking in',
            'body' => 'Hi {{first_name}}, just checking in.', 'is_active' => true,
        ]);
    }

    /** Another organization's designed template, created in its own tenant. */
    private function theirTemplate(): EmailTemplate
    {
        $theirOrg = app(OrgOnboardingService::class)->createOrg(
            'Org theirs', 'Owner theirs', 'owner-theirs@client.ph', 'password-123', ['crm' => 'full'],
        )['org'];
        $template = CurrentOrg::runAs($theirOrg->id, fn (): EmailTemplate => EmailTemplate::create([
            'purpose' => 'Introduction', 'tone' => 'Their tone', 'subject' => 'Theirs', 'body' => 'Theirs',
            'content' => $this->content([$this->heading(['text' => 'Theirs'])]),
            'email_design_id' => EmailDesign::create(['name' => 'Their design', 'is_default' => false, 'layout' => $this->layout()])->id,
            'is_active' => true,
        ]));
        CurrentOrg::clear();

        return $template;
    }

    public function test_a_draft_saved_from_a_designed_template_keeps_the_template(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $template = $this->designedTemplate();

        $response = $this->saveDraft($lead, [
            'subject' => 'A quick idea',
            'body' => $this->asOffered($template, $lead, $sender),
            'email_template_id' => (string) $template->id,
        ])->assertCreated();

        $response->assertJsonPath('data.status', 'draft')->assertJsonPath('data.emailTemplateId', (string) $template->id);
        $this->assertSame($template->id, Email::query()->findOrFail($response->json('data.id'))->email_template_id);
        $this->assertCount(0, Mail::mailer()->getSymfonyTransport()->messages(), 'Saving a draft never sends.');
    }

    public function test_a_draft_from_a_plain_template_has_no_design_to_carry(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $plain = $this->plainTemplate();

        // The composer names a plain template exactly as a send does; it identifies the template, not a design.
        $draft = $this->draftFrom($lead, $this->asOffered($plain, $lead, $sender), $plain);
        $this->assertSame($plain->id, $draft->email_template_id);
        $this->assertFalse($this->feedEntry($lead, $plain)['isDesigned']);

        $this->postJson("/api/v1/crm/emails/{$draft->id}/send")->assertOk();

        $this->assertNull($this->lastSent()->getHtmlBody(), 'A plain template has no design to render.');
        $this->assertNull($draft->fresh()->body_html);

        // A draft composed without any template records none.
        $this->assertNull($this->draftFrom($lead, 'Written from scratch', null)->email_template_id);
    }

    public function test_a_reopened_draft_names_its_template_so_the_composer_can_restore_it(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $template = $this->designedTemplate();
        $draft = $this->draftFrom($lead, $this->asOffered($template, $lead, $sender), $template);

        $reopened = collect($this->getJson('/api/v1/crm/emails?status=draft')->assertOk()->json('data'))
            ->firstWhere('id', (string) $draft->id);

        $this->assertIsArray($reopened);
        $this->assertSame((string) $template->id, $reopened['emailTemplateId']);
        $this->assertArrayNotHasKey('bodyHtml', $reopened);

        // What the composer looks that id up in: the same feed, which gives its Purpose + Tone and design.
        $entry = $this->feedEntry($lead, $template);
        $this->assertSame(['Introduction', 'Warm & professional'], [$entry['purpose'], $entry['tone']]);
        $this->assertTrue($entry['isDesigned']);
        $this->assertSame('Brand design', $entry['designName']);
        $this->assertSame($entry['body'], $reopened['body'], 'Unedited, the reopened body still matches the template.');
    }

    public function test_sending_an_unedited_designed_draft_renders_its_design(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $template = $this->designedTemplate();
        $draft = $this->draftFrom($lead, $this->asOffered($template, $lead, $sender), $template);

        $this->postJson("/api/v1/crm/emails/{$draft->id}/send")->assertOk()->assertJsonPath('data.status', 'sent');

        $sentHtml = (string) $this->lastSent()->getHtmlBody();
        $expected = app(EmailDesignRenderer::class)->toHtml(
            $template->design->layout,
            $template->content,
            app(EmailTemplateDesignService::class)->sendContext($lead, $sender),
        );
        $this->assertSame($expected, $sentHtml, 'The draft goes through the existing renderer and send context.');
        $this->assertSame($sentHtml, $draft->fresh()->body_html, 'The snapshot is what the transport received.');
        $this->assertSame((string) $draft->fresh()->body, (string) $this->lastSent()->getTextBody());
        $this->assertSame(1, Email::query()->count(), 'The draft row itself became the sent email.');
    }

    public function test_an_edited_designed_draft_sends_plain_text(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $template = $this->designedTemplate();

        // What the composer does: edited text is no longer sendable as the template, so it names none.
        $cleared = $this->draftFrom($lead, "Hi Maria,\n\nI rewrote this myself.", null);
        $this->postJson("/api/v1/crm/emails/{$cleared->id}/send")->assertOk();
        $this->assertNull($this->lastSent()->getHtmlBody());
        $this->assertNull($cleared->fresh()->body_html);

        // A client that kept the id anyway: the existing edit check still refuses the design.
        $kept = $this->draftFrom($lead, "Hi Maria,\n\nI rewrote this too.", $template);
        $this->assertSame($template->id, $kept->email_template_id);
        $this->postJson("/api/v1/crm/emails/{$kept->id}/send")->assertOk();
        $this->assertNull($this->lastSent()->getHtmlBody());
        $this->assertStringContainsString('I rewrote this too.', (string) $this->lastSent()->getTextBody());
        $this->assertNull($kept->fresh()->body_html);
    }

    public function test_updating_a_draft_keeps_a_valid_template_and_clears_one_it_no_longer_names(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $template = $this->designedTemplate();
        $offered = $this->asOffered($template, $lead, $sender);
        $draft = $this->draftFrom($lead, $offered, $template);

        // Re-saved unedited, naming the template: kept.
        $this->patchJson("/api/v1/crm/emails/{$draft->id}", [
            'subject' => 'A quick idea', 'body' => $offered, 'email_template_id' => (string) $template->id,
        ])->assertOk()->assertJsonPath('data.emailTemplateId', (string) $template->id);
        $this->assertSame($template->id, $draft->fresh()->email_template_id);

        // Re-saved after an edit with an explicit null: cleared.
        $this->patchJson("/api/v1/crm/emails/{$draft->id}", [
            'subject' => 'A quick idea', 'body' => 'Edited', 'email_template_id' => null,
        ])->assertOk()->assertJsonPath('data.emailTemplateId', null);
        $this->assertNull($draft->fresh()->email_template_id);

        // Re-associated, then re-saved by a client that omits the field: the server clears it, so a stale
        // association never outlives the save it was valid for.
        $this->patchJson("/api/v1/crm/emails/{$draft->id}", [
            'subject' => 'A quick idea', 'body' => $offered, 'email_template_id' => (string) $template->id,
        ])->assertOk();
        $this->patchJson("/api/v1/crm/emails/{$draft->id}", ['subject' => 'A quick idea', 'body' => 'Edited again'])
            ->assertOk()->assertJsonPath('data.emailTemplateId', null);
        $this->assertNull($draft->fresh()->email_template_id);

        $this->postJson("/api/v1/crm/emails/{$draft->id}/send")->assertOk();
        $this->assertNull($this->lastSent()->getHtmlBody());
    }

    public function test_a_draft_cannot_name_another_organizations_template(): void
    {
        $theirs = $this->theirTemplate();

        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();

        $this->saveDraft($lead, ['subject' => 'Draft', 'body' => 'x', 'email_template_id' => (string) $theirs->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email_template_id');
        $this->assertSame(0, Email::withoutGlobalScopes()->count(), 'No draft is created.');

        // Nor can an existing draft be pointed at it.
        $draft = $this->draftFrom($lead, 'Mine', null);
        $this->patchJson("/api/v1/crm/emails/{$draft->id}", ['subject' => 'Draft', 'body' => 'Mine', 'email_template_id' => (string) $theirs->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email_template_id');
        $this->assertNull(Email::withoutGlobalScopes()->findOrFail($draft->id)->email_template_id);
    }

    public function test_a_draft_cannot_name_a_deleted_or_unknown_template(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $deleted = $this->designedTemplate();
        $deleted->delete();

        foreach ([(string) $deleted->id, '999999', 'not-a-number'] as $id) {
            $this->saveDraft($lead, ['subject' => 'Draft', 'body' => 'x', 'email_template_id' => $id])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('email_template_id');
        }
        $this->assertSame(0, Email::withoutGlobalScopes()->count());
    }

    /** A template deleted after the draft was saved: the draft still sends, as the plain text it holds. */
    public function test_a_draft_whose_template_was_deleted_later_sends_plain_text(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $template = $this->designedTemplate();
        $draft = $this->draftFrom($lead, $this->asOffered($template, $lead, $sender), $template);

        $template->delete();

        $this->postJson("/api/v1/crm/emails/{$draft->id}/send")->assertOk()->assertJsonPath('data.status', 'sent');
        $this->assertNull($this->lastSent()->getHtmlBody());
        $this->assertNull($draft->fresh()->body_html);
    }

    // ── 14. A SEND NAMES ONLY THIS ORGANIZATION'S LIVE TEMPLATES ─────────────────────────────
    //
    // The same scope SaveDraftRequest applies: another organization's id, or a soft-deleted one, is
    // refused at the request boundary — nothing is stored and nothing is sent.

    public function test_a_send_cannot_name_another_organizations_template(): void
    {
        $theirs = $this->theirTemplate();

        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();

        $this->send($lead, ['subject' => 'A quick idea', 'body' => 'Theirs', 'email_template_id' => (string) $theirs->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email_template_id');

        $this->assertSame(0, Email::withoutGlobalScopes()->count(), 'No email row is created.');
        $this->assertCount(0, Mail::mailer()->getSymfonyTransport()->messages(), 'Nothing is sent.');
    }

    public function test_a_send_cannot_name_a_soft_deleted_template(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $template = $this->designedTemplate();
        $offered = $this->asOffered($template, $lead, $sender);
        $template->delete();

        $this->send($lead, ['subject' => 'A quick idea', 'body' => $offered, 'email_template_id' => (string) $template->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email_template_id');

        $this->assertCount(0, Mail::mailer()->getSymfonyTransport()->messages(), 'Nothing is sent.');
    }

    public function test_a_send_naming_this_organizations_template_still_sends_its_design(): void
    {
        $sender = $this->admin();
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $template = $this->designedTemplate();

        $this->send($lead, ['subject' => 'A quick idea', 'body' => $this->asOffered($template, $lead, $sender), 'email_template_id' => (string) $template->id])
            ->assertCreated()
            ->assertJsonPath('data.status', 'sent');

        $this->assertNotNull($this->lastSent()->getHtmlBody(), 'A valid same-organization template still renders its design.');
        $this->assertSame($template->id, Email::query()->latest('id')->firstOrFail()->email_template_id);
    }
}
