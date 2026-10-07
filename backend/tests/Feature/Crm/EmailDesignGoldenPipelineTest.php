<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Jobs\SendScheduledEmailsJob;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\EmailDesign;
use App\Modules\Crm\Models\EmailTemplate;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Outreach\EmailBrandingService;
use App\Modules\Crm\Services\Outreach\EmailTemplateDesignService;
use App\Modules\Crm\Services\Outreach\TemplateRenderer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Mime\Email as MimeEmail;
use Tests\TestCase;
use Tests\Unit\Crm\EmailDesign\EmailDesignGoldenTest;

/**
 * The frozen v1 corpus (see EmailDesignGoldenTest) reached through every way the product renders a designed
 * email, end to end: a composer send of a designed template, its scheduled delivery, the composer's per-lead
 * preview, a Lead Details designed reply (preview, send and its text part), the Settings template and design
 * previews, and the Gmail guard's measurement.
 *
 * EmailDesignGoldenTest proves the RENDERER still produces the frozen bytes; this proves the CALLERS still
 * hand it the same documents and context — tokens from a real lead and sender, the branding logo, the sample
 * values — and still store and send exactly what it returns. Together they are the guarantee that existing
 * designs and existing sends stay byte-identical while the design system grows.
 *
 * The lead, sender and logo below are the `send` and `sample` contexts of the corpus; changing them here
 * breaks every comparison, which is the point.
 */
class EmailDesignGoldenPipelineTest extends TestCase
{
    use RefreshDatabase;

    private const LOGO_URL = 'https://crm.viteseo.test/api/v1/crm/email-assets/0b3d6c1e-7a2f-4c55-9e1d-2f6a8b7c9d01.png';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::mailer()->getSymfonyTransport()->flush();

        // The corpus' branding logo: the `send` context's public URL, and the `sample` context's inline bytes.
        $logo = ['url' => self::LOGO_URL, 'png' => base64_decode(EmailDesignGoldenTest::json('contexts.json')['sample']['previewImages'][self::LOGO_URL]), 'alt' => 'Acme'];
        $this->mock(EmailBrandingService::class, function ($mock) use ($logo): void {
            $mock->shouldReceive('logoForRender')->andReturn($logo);
        });
    }

    // ── Fixtures matching the corpus' `send` context ────────────────────────────────────────

    private function sender(): User
    {
        $sender = User::factory()->create(['status' => 'active', 'name' => 'Jamie Reyes']);
        $sender->assignRole('crm_admin');

        return $sender;
    }

    private function lead(): Lead
    {
        return Lead::factory()->create([
            'email' => 'prospect@acme.test',
            'contact_name' => 'Maria Santos',
            'business_name' => 'Acme Bakery',
            'industry' => 'Bakery',
            'city' => 'Quezon City',
        ]);
    }

    /** @return array<string, array{layout: string, content: string, context: string}> */
    private function cases(string $prefix, string $context): array
    {
        return array_filter(
            EmailDesignGoldenTest::json('cases.json'),
            fn (array $case, string $name): bool => str_starts_with($name, $prefix) && $case['context'] === $context,
            ARRAY_FILTER_USE_BOTH,
        );
    }

    private function design(string $layoutKey): EmailDesign
    {
        return EmailDesign::create(['name' => $layoutKey, 'is_default' => false, 'layout' => EmailDesignGoldenTest::json("documents/{$layoutKey}.json")]);
    }

    private function template(EmailDesign $design, string $contentKey): EmailTemplate
    {
        $content = EmailDesignGoldenTest::json("documents/{$contentKey}.json");

        return EmailTemplate::create([
            'purpose' => 'Follow-up',
            'tone' => 'Warm & professional',
            'subject' => 'A quick idea for {{business_name}}',
            'body' => app(EmailTemplateDesignService::class)->plainTextBody($design->layout, $content),
            'content' => $content,
            'email_design_id' => $design->id,
            'is_active' => true,
        ]);
    }

    private function lastSent(): MimeEmail
    {
        $message = Mail::mailer()->getSymfonyTransport()->messages()->last()?->getOriginalMessage();
        $this->assertInstanceOf(MimeEmail::class, $message, 'Expected an email to have been sent.');

        return $message;
    }

    // ── Composer: designed template ─────────────────────────────────────────────────────────

    public function test_every_local_designed_template_sends_and_stores_its_golden_html(): void
    {
        $sender = $this->sender();
        Sanctum::actingAs($sender);
        $lead = $this->lead();

        $cases = $this->cases('local-template-', 'send');
        $this->assertCount(15, $cases);

        foreach ($cases as $name => $case) {
            $golden = EmailDesignGoldenTest::expected("{$name}.html");
            $template = $this->template($this->design($case['layout']), $case['content']);

            // The plain-text body stored beside a designed template is the corpus' placeholder rendering.
            $this->assertSame(EmailDesignGoldenTest::expected(str_replace('--send', '--placeholders', $name).'.txt'), $template->body, "{$name}: stored body");

            $preview = $this->getJson("/api/v1/crm/leads/{$lead->id}/email-templates/{$template->id}/preview")->assertOk();
            $this->assertSame($golden, $preview->json('data.html'), "{$name}: composer per-lead preview");

            $id = $this->postJson("/api/v1/crm/leads/{$lead->id}/emails", [
                'subject' => 'A quick idea',
                'body' => app(TemplateRenderer::class)->render($template->subject, $template->body, $lead, $sender)['body'],
                'email_template_id' => (string) $template->id,
            ])->assertCreated()->json('data.id');

            $this->assertSame($golden, (string) $this->lastSent()->getHtmlBody(), "{$name}: sent text/html part");
            $this->assertSame($golden, Email::query()->findOrFail($id)->body_html, "{$name}: stored body_html");
        }
    }

    public function test_a_scheduled_designed_template_is_delivered_with_its_golden_html(): void
    {
        $sender = $this->sender();
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $case = EmailDesignGoldenTest::json('cases.json')['local-template-6--send'];
        $template = $this->template($this->design($case['layout']), $case['content']);

        $id = $this->postJson("/api/v1/crm/leads/{$lead->id}/emails", [
            'subject' => 'A quick idea',
            'body' => app(TemplateRenderer::class)->render($template->subject, $template->body, $lead, $sender)['body'],
            'email_template_id' => (string) $template->id,
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])->assertCreated()->assertJsonPath('data.status', 'scheduled')->json('data.id');

        $this->travel(2)->days();
        SendScheduledEmailsJob::dispatchSync();

        $golden = EmailDesignGoldenTest::expected('local-template-6--send.html');
        $this->assertSame($golden, (string) $this->lastSent()->getHtmlBody());
        $this->assertSame($golden, Email::withoutGlobalScopes()->findOrFail($id)->body_html);
    }

    // ── Lead Details: designed reply ────────────────────────────────────────────────────────

    public function test_a_designed_reply_in_every_live_local_design_previews_sends_and_stores_its_golden_parts(): void
    {
        $sender = $this->sender();
        Sanctum::actingAs($sender);
        $lead = $this->lead();
        $replyDoc = EmailDesignGoldenTest::json('documents/content.reply.json')['blocks'][0]['content'];

        $cases = $this->cases('local-design-', 'send');
        $this->assertCount(6, $cases);

        foreach ($cases as $name => $case) {
            $design = $this->design($case['layout']);
            $html = EmailDesignGoldenTest::expected("{$name}.html");
            $payload = ['email_design_id' => (string) $design->id, 'body_doc' => $replyDoc];

            $preview = $this->postJson("/api/v1/crm/leads/{$lead->id}/emails/design-preview", $payload)->assertOk();
            $this->assertSame($html, $preview->json('data.html'), "{$name}: reply preview");

            $id = $this->postJson("/api/v1/crm/leads/{$lead->id}/emails", [...$payload, 'subject' => 'Re: A quick idea', 'body' => 'reply'])
                ->assertCreated()->json('data.id');

            $sent = $this->lastSent();
            $this->assertSame($html, (string) $sent->getHtmlBody(), "{$name}: sent text/html part");
            $this->assertSame(EmailDesignGoldenTest::expected("{$name}.txt"), (string) $sent->getTextBody(), "{$name}: sent text/plain part");
            $this->assertSame($html, Email::query()->findOrFail($id)->body_html, "{$name}: stored body_html");
        }
    }

    // ── Settings previews and the Gmail guard ───────────────────────────────────────────────

    public function test_the_settings_template_preview_and_the_gmail_guard_match_the_corpus(): void
    {
        Sanctum::actingAs($this->sender());

        $cases = $this->cases('local-template-', 'sample');
        $this->assertCount(15, $cases);

        foreach ($cases as $name => $case) {
            $design = $this->design($case['layout']);
            $response = $this->postJson('/api/v1/crm/settings/email-templates/preview', [
                'content' => json_encode(EmailDesignGoldenTest::json("documents/{$case['content']}.json")),
                'email_design_id' => $design->id,
                'subject' => 'A quick idea',
            ])->assertOk();

            $this->assertSame(EmailDesignGoldenTest::expected("{$name}.html"), $response->json('data.html'), "{$name}: Settings preview html");
            $this->assertSame(EmailDesignGoldenTest::expected("{$name}.txt"), $response->json('data.text'), "{$name}: Settings preview text");
            $this->assertSame(
                strlen(EmailDesignGoldenTest::expected(str_replace('--sample', '--guard', $name).'.html')),
                $response->json('data.htmlBytes'),
                "{$name}: the Gmail guard's measured size",
            );
        }
    }

    public function test_the_settings_design_preview_matches_the_corpus_for_every_local_design(): void
    {
        Sanctum::actingAs($this->sender());

        $cases = $this->cases('local-design-', 'sample');
        $this->assertCount(6, $cases);

        foreach ($cases as $name => $case) {
            $response = $this->postJson('/api/v1/crm/settings/email-designs/preview', [
                'layout' => json_encode(EmailDesignGoldenTest::json("documents/{$case['layout']}.json")),
            ])->assertOk();

            $this->assertSame(EmailDesignGoldenTest::expected("{$name}.html"), $response->json('data.html'), "{$name}: Settings design preview html");
            $this->assertSame(EmailDesignGoldenTest::expected("{$name}.txt"), $response->json('data.text'), "{$name}: Settings design preview text");
        }
    }
}
