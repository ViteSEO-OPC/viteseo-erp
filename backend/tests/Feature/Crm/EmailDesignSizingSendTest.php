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
 * A design that sets the card, row, signature and button sizing styles: the composer's preview, the send, body_html
 * and a scheduled delivery are the same bytes, and they carry the sizes.
 */
class EmailDesignSizingSendTest extends TestCase
{
    use BuildsEmailDesigns;
    use RefreshDatabase;

    public function test_a_sized_design_previews_sends_stores_and_is_delivered_as_the_same_bytes(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::mailer()->getSymfonyTransport()->flush();
        $sender = User::factory()->create(['status' => 'active', 'name' => 'Uriel Papa', 'phone' => '0945 449 3862']);
        $sender->assignRole('crm_admin');
        Sanctum::actingAs($sender);
        $lead = Lead::factory()->create(['email' => 'sarah@revora.test', 'contact_name' => 'Sarah Cruz', 'business_name' => 'Revora']);

        $design = EmailDesign::create(['name' => 'Sized', 'is_default' => false, 'layout' => $this->layout([], [], [
            'card_eyebrow_size' => 13, 'card_title_size' => 22, 'row_title_size' => 16, 'row_description_size' => 15, 'row_padding' => 14,
            'signature_size' => 17, 'signature_detail_size' => 15, 'button_padding_y' => 14, 'button_padding_x' => 28,
        ], ['show_phone' => true])]);
        $content = $this->content([$this->button(), $this->card(['blocks' => [$this->rows(['id' => 'r'])]]), $this->actions()]);
        $template = EmailTemplate::create([
            'purpose' => 'Follow-up', 'tone' => 'Warm & professional', 'subject' => 'Your quotation',
            'body' => app(EmailTemplateDesignService::class)->plainTextBody($design->layout, $content),
            'content' => $content, 'email_design_id' => $design->id, 'is_active' => true,
        ]);
        $payload = fn (array $extra = []): array => [
            'subject' => 'Your quotation',
            'body' => app(TemplateRenderer::class)->render($template->subject, $template->body, $lead, $sender)['body'],
            'email_template_id' => (string) $template->id,
            ...$extra,
        ];

        $preview = $this->getJson("/api/v1/crm/leads/{$lead->id}/email-templates/{$template->id}/preview")->assertOk()->json('data.html');
        $id = $this->postJson("/api/v1/crm/leads/{$lead->id}/emails", $payload())->assertCreated()->json('data.id');
        $sent = Mail::mailer()->getSymfonyTransport()->messages()->last()?->getOriginalMessage();
        $this->assertInstanceOf(MimeEmail::class, $sent);

        $this->assertSame($preview, (string) $sent->getHtmlBody());
        $this->assertSame($preview, Email::query()->findOrFail($id)->body_html);
        $this->assertSame(2, substr_count($preview, 'padding:14px 28px;'));
        $this->assertStringContainsString('font-size:22px;line-height:1.3;font-weight:bold', $preview);
        $this->assertStringContainsString('font-size:17px;line-height:1.5;color:#18181b;"><strong>Uriel Papa</strong>', $preview);
        $this->assertStringContainsString('font-size:15px;line-height:1.5;color:#18181b;"><a href="tel:09454493862"', $preview);

        $scheduled = $this->postJson("/api/v1/crm/leads/{$lead->id}/emails", $payload(['scheduled_at' => now()->addDay()->toIso8601String()]))->assertCreated()->json('data.id');
        $this->travel(2)->days();
        SendScheduledEmailsJob::dispatchSync();
        $this->assertSame($preview, Email::withoutGlobalScopes()->findOrFail($scheduled)->body_html);
    }
}
