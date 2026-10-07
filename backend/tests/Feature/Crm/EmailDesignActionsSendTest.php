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
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Mime\Email as MimeEmail;
use Tests\TestCase;
use Tests\Unit\Crm\EmailDesign\BuildsEmailDesigns;

/**
 * Actions rows and un-underlined links through every path a designed template takes — the composer's preview, a
 * send, body_html, a scheduled delivery and the Settings preview — as the same bytes, and the Settings rule that
 * an actions row is readable content.
 */
class EmailDesignActionsSendTest extends TestCase
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

    private function template(): EmailTemplate
    {
        $design = EmailDesign::create(['name' => 'Proposal', 'is_default' => false, 'layout' => $this->layout(
            [],
            [$this->divider(['id' => 'f-rule']), $this->actions(['id' => 'f-actions', 'items' => [['kind' => 'link', 'label' => 'Unsubscribe', 'url' => 'https://viteseo.ph/unsubscribe']]])],
            ['link_underline' => false, 'secondary_color' => '#1C1C1E'],
        )]);
        $content = $this->content([
            $this->textBlock(['id' => 'hi', 'content' => $this->doc($this->para($this->text('Hi {{first_name}}, see '), $this->text('the proposal', [$this->link('https://viteseo.ph/p')])))]),
            $this->card(['id' => 'c', 'blocks' => [$this->rows(['id' => 'r']), $this->actions(['id' => 'card-actions', 'items' => [['kind' => 'link', 'label' => 'See the NFC card quote ›', 'url' => 'https://viteseo.ph/nfc']]])]]),
            $this->actions(['id' => 'cta']),
        ]);

        return EmailTemplate::create([
            'purpose' => 'Follow-up',
            'tone' => 'Warm & professional',
            'subject' => 'Your proposal',
            'body' => app(EmailTemplateDesignService::class)->plainTextBody($design->layout, $content),
            'content' => $content,
            'email_design_id' => $design->id,
            'is_active' => true,
        ]);
    }

    private function lastSent(): MimeEmail
    {
        $message = Mail::mailer()->getSymfonyTransport()->messages()->last()?->getOriginalMessage();
        $this->assertInstanceOf(MimeEmail::class, $message);

        return $message;
    }

    private function payload(EmailTemplate $template, Lead $lead, User $sender, array $extra = []): array
    {
        return [
            'subject' => 'Your proposal',
            'body' => app(TemplateRenderer::class)->render($template->subject, $template->body, $lead, $sender)['body'],
            'email_template_id' => (string) $template->id,
            ...$extra,
        ];
    }

    public function test_an_actions_template_previews_sends_stores_and_is_delivered_as_the_same_bytes(): void
    {
        $sender = $this->sender();
        Sanctum::actingAs($sender);
        $lead = Lead::factory()->create(['email' => 'mj@gcamp.test', 'contact_name' => 'MJ Reyes', 'business_name' => 'GCamp']);
        $template = $this->template();

        $preview = $this->getJson("/api/v1/crm/leads/{$lead->id}/email-templates/{$template->id}/preview")->assertOk()->json('data.html');
        $id = $this->postJson("/api/v1/crm/leads/{$lead->id}/emails", $this->payload($template, $lead, $sender))->assertCreated()->json('data.id');
        $sent = $this->lastSent();

        $this->assertSame($preview, (string) $sent->getHtmlBody());
        $this->assertSame($preview, Email::query()->findOrFail($id)->body_html);
        $this->assertStringContainsString('>Contact us</a>', $preview);
        $this->assertStringContainsString('See our portfolio ›</a>', $preview);
        $this->assertStringContainsString('See the NFC card quote ›</a>', $preview);
        $this->assertStringContainsString('>Unsubscribe</a>', $preview);
        $this->assertStringNotContainsString('text-decoration:underline', $preview, 'The design turned underlines off everywhere.');
        $this->assertStringContainsString('Contact us: mailto:hello@viteseo.ph', (string) $sent->getTextBody());

        // Scheduled for later, it is delivered in the same bytes.
        $scheduled = $this->postJson("/api/v1/crm/leads/{$lead->id}/emails", $this->payload($template, $lead, $sender, ['scheduled_at' => now()->addDay()->toIso8601String()]))
            ->assertCreated()->json('data.id');
        $this->travel(2)->days();
        SendScheduledEmailsJob::dispatchSync();
        $this->assertSame($preview, Email::withoutGlobalScopes()->findOrFail($scheduled)->body_html);
    }

    public function test_the_settings_preview_renders_actions(): void
    {
        Sanctum::actingAs($this->sender());
        $template = $this->template();

        $response = $this->postJson('/api/v1/crm/settings/email-templates/preview', [
            'content' => json_encode($template->content),
            'email_design_id' => $template->email_design_id,
            'subject' => 'Your proposal',
        ])->assertOk();

        $this->assertStringContainsString('<!--[if mso]><table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center"><tr><![endif]-->', $response->json('data.html'));
        $this->assertStringContainsString("Contact us: mailto:hello@viteseo.ph\nSee our portfolio ›: https://viteseo.ph/work", $response->json('data.text'));
    }

    public function test_an_actions_row_is_readable_content_on_its_own(): void
    {
        $designs = app(EmailTemplateDesignService::class);

        $this->assertNull($designs->contentProblem((string) json_encode($this->content([$this->actions()])), $this->layout()));
        $this->assertSame(
            EmailTemplateDesignService::NO_READABLE_CONTENT,
            $designs->contentProblem((string) json_encode($this->content([$this->actions(['items' => [['kind' => 'link', 'label' => "\u{200B}", 'url' => 'https://viteseo.ph']]])])), $this->layout())['message'] ?? null,
        );
    }
}
