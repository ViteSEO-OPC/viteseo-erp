<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Jobs\SendScheduledEmailsJob;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\EmailDesign;
use App\Modules\Crm\Models\EmailTemplate;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Outreach\EmailTemplateDesignService;
use App\Modules\Crm\Services\Outreach\TemplateRenderer;
use App\Modules\Crm\Support\EmailDesign\EmailDesignSchema;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Mime\Email as MimeEmail;
use Tests\TestCase;
use Tests\Unit\Crm\EmailDesign\BuildsEmailDesigns;

/**
 * A quotation-shaped template — heading, greeting, button, a card of rows, closing text — through every path a
 * designed template takes: the composer's per-lead preview, a send, its body_html, a scheduled delivery and the
 * Settings preview, all the same bytes; and the Settings rules that guard what a template may be saved as.
 */
class EmailDesignCardRowsSendTest extends TestCase
{
    use BuildsEmailDesigns;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::mailer()->getSymfonyTransport()->flush();
    }

    private function sender(): User
    {
        $sender = User::factory()->create(['status' => 'active', 'name' => 'Uriel Papa']);
        $sender->assignRole('crm_admin');

        return $sender;
    }

    private function lead(): Lead
    {
        return Lead::factory()->create(['email' => 'sarah@revora.test', 'contact_name' => 'Sarah Cruz', 'business_name' => 'Revora']);
    }

    /** @return array<string, mixed> */
    private function quotation(): array
    {
        return $this->content([
            $this->heading(['id' => 'h', 'text' => 'Your website quotation.', 'align' => 'center', 'font_size' => 36]),
            $this->textBlock(['id' => 'hi', 'align' => 'center', 'content' => $this->doc($this->para($this->text('Hi {{first_name}},')))]),
            $this->button(['id' => 'view', 'label' => 'View the full quotation', 'url' => 'https://viteseo.ph/q/revora', 'radius' => 24]),
            $this->card(['id' => 'summary', 'eyebrow' => 'Quotation summary', 'title' => 'Basic website. ₱35,000, one-time.', 'blocks' => [
                $this->divider(['id' => 'rule']),
                $this->rows(['id' => 'lines', 'items' => [
                    ['title' => 'Website', 'description' => '5 pages for {{business_name}}, with a contact form.'],
                    ['title' => 'Payment', 'description' => '₱17,500 on signing and ₱17,500 on delivery.'],
                ]]),
                $this->textBlock(['id' => 'fine', 'font_size' => 12, 'align' => 'center', 'content' => $this->doc($this->para($this->text('Prices in Philippine pesos, VAT not included.')))]),
            ]]),
            $this->button(['id' => 'portfolio', 'variant' => 'secondary', 'label' => 'See our portfolio', 'url' => 'https://viteseo.ph/work', 'radius' => 24]),
        ]);
    }

    private function template(array $content): EmailTemplate
    {
        $design = EmailDesign::create(['name' => 'Proposal', 'is_default' => false, 'layout' => $this->layout([], [], ['container_radius' => 16, 'content_padding' => 48])]);

        return EmailTemplate::create([
            'purpose' => 'Follow-up',
            'tone' => 'Warm & professional',
            'subject' => 'Your website quotation',
            'body' => app(EmailTemplateDesignService::class)->plainTextBody($design->layout, $content),
            'content' => $content,
            'email_design_id' => $design->id,
            'is_active' => true,
        ]);
    }

    private function body(EmailTemplate $template, Lead $lead, User $sender): string
    {
        return app(TemplateRenderer::class)->render($template->subject, $template->body, $lead, $sender)['body'];
    }

    private function lastSent(): MimeEmail
    {
        $message = Mail::mailer()->getSymfonyTransport()->messages()->last()?->getOriginalMessage();
        $this->assertInstanceOf(MimeEmail::class, $message);

        return $message;
    }

    public function test_a_card_template_previews_sends_and_stores_the_same_bytes(): void
    {
        $sender = $this->sender();
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $template = $this->template($this->quotation());

        $preview = $this->getJson("/api/v1/crm/leads/{$lead->id}/email-templates/{$template->id}/preview")->assertOk()->json('data.html');
        $id = $this->postJson("/api/v1/crm/leads/{$lead->id}/emails", [
            'subject' => 'Your website quotation',
            'body' => $this->body($template, $lead, $sender),
            'email_template_id' => (string) $template->id,
        ])->assertCreated()->json('data.id');
        $sent = $this->lastSent();

        $this->assertSame($preview, (string) $sent->getHtmlBody());
        $this->assertSame($preview, Email::query()->findOrFail($id)->body_html);
        $this->assertStringContainsString('Basic website. ₱35,000, one-time.</p>', $preview);
        $this->assertStringContainsString('5 pages for Revora, with a contact form.</p>', $preview);
        $this->assertStringContainsString('border-radius:16px;', $preview);
        $this->assertStringContainsString('font-size:36px;', $preview);
        // The plain-text part of a template send is the template's stored body, filled in for this lead.
        $this->assertStringContainsString("Quotation summary\nBasic website. ₱35,000, one-time.", (string) $sent->getTextBody());
        $this->assertStringContainsString("Website\n5 pages for Revora, with a contact form.", (string) $sent->getTextBody());
    }

    public function test_a_scheduled_card_template_is_delivered_in_its_design(): void
    {
        $sender = $this->sender();
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $template = $this->template($this->quotation());
        $preview = $this->getJson("/api/v1/crm/leads/{$lead->id}/email-templates/{$template->id}/preview")->assertOk()->json('data.html');

        $id = $this->postJson("/api/v1/crm/leads/{$lead->id}/emails", [
            'subject' => 'Your website quotation',
            'body' => $this->body($template, $lead, $sender),
            'email_template_id' => (string) $template->id,
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])->assertCreated()->json('data.id');
        $this->travel(2)->days();
        SendScheduledEmailsJob::dispatchSync();

        $this->assertSame($preview, (string) $this->lastSent()->getHtmlBody());
        $this->assertSame($preview, Email::withoutGlobalScopes()->findOrFail($id)->body_html);
    }

    public function test_the_settings_template_preview_renders_cards_and_rows(): void
    {
        Sanctum::actingAs($this->sender());
        $template = $this->template($this->quotation());

        $response = $this->postJson('/api/v1/crm/settings/email-templates/preview', [
            'content' => json_encode($this->quotation()),
            'email_design_id' => $template->email_design_id,
            'subject' => 'Your website quotation',
        ])->assertOk();

        $this->assertStringContainsString('5 pages for Santos Bakery, with a contact form.</p>', $response->json('data.html'));
        $this->assertStringContainsString("Website\n5 pages for Santos Bakery, with a contact form.", $response->json('data.text'));
        $this->assertLessThan(EmailTemplateDesignService::MAX_HTML_BYTES, $response->json('data.htmlBytes'));
    }

    public function test_a_card_or_rows_counts_as_readable_content_and_a_card_of_rules_does_not(): void
    {
        $designs = app(EmailTemplateDesignService::class);
        $layout = $this->layout();

        $this->assertNull($designs->contentProblem((string) json_encode($this->content([$this->rows()])), $layout));
        $this->assertNull($designs->contentProblem((string) json_encode($this->content([$this->card(['eyebrow' => null, 'title' => null])])), $layout));
        $this->assertNull($designs->contentProblem((string) json_encode($this->content([$this->card(['blocks' => [$this->divider()]])])), $layout), 'Its title is readable.');

        $silent = $this->card(['eyebrow' => null, 'title' => null, 'blocks' => [$this->divider(), $this->spacer()]]);
        $this->assertSame(
            EmailTemplateDesignService::NO_READABLE_CONTENT,
            $designs->contentProblem((string) json_encode($this->content([$silent])), $layout)['message'] ?? null,
        );
    }

    public function test_a_card_template_too_large_for_gmail_is_refused_when_saved(): void
    {
        $designs = app(EmailTemplateDesignService::class);
        $long = str_repeat('Lorem ipsum dolor sit amet. ', 30);
        $card = fn (int $n): array => $this->card(['id' => "c{$n}", 'blocks' => [$this->rows([
            'id' => "r{$n}",
            'items' => array_fill(0, EmailDesignSchema::MAX_ROWS_ITEMS, ['title' => 'Line', 'description' => mb_substr($long, 0, EmailDesignSchema::MAX_ROW_DESCRIPTION)]),
        ])]]);
        $content = $this->content([$card(1), $card(2), $card(3)]);
        $json = (string) json_encode($content);
        $this->assertLessThan(EmailDesignSchema::MAX_BYTES, strlen($json), 'Valid as a document; only the rendered size is too big.');

        $this->assertSame(EmailTemplateDesignService::HTML_TOO_LARGE, $designs->contentProblem($json, $this->layout())['message'] ?? null);
    }
}
