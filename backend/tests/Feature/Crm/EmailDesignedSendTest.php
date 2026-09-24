<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\EmailDesign;
use App\Modules\Crm\Models\EmailTemplate;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Outreach\EmailTemplateDesignService;
use App\Modules\Crm\Services\Outreach\OutreachEmailService;
use App\Modules\Crm\Services\Outreach\TemplateRenderer;
use App\Modules\Crm\Support\EmailDesign\EmailDesignRenderer;
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
    private function designedTemplate(array $contentOverride = []): EmailTemplate
    {
        $design = EmailDesign::create([
            'name' => 'Brand design',
            'is_default' => true,
            'layout' => $this->layout(styles: [
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
}
