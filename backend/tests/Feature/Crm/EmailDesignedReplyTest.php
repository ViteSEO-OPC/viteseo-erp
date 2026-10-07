<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\EmailDesign;
use App\Modules\Crm\Models\EmailTemplate;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Mail\EmailDocument;
use App\Modules\Crm\Services\Outreach\EmailTemplateDesignService;
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
 * Lead Details → Reply in an existing Email Design.
 *
 * The BD writes the reply; the design — chosen from Settings, never edited here — frames it. Three claims:
 *
 *  1. PREVIEW = SEND = STORED. The preview endpoint, the HTML the transport received and `body_html` are the
 *     same bytes for the same lead, sender, reply and design. One sequence (designedReplyHtml) feeds all three.
 *  2. NO DESIGN CHANGES NOTHING. A reply without a design is sent exactly as formatted replies always were.
 *  3. A CHOSEN DESIGN IS NEVER SILENTLY DROPPED. Anything that would stop the design from carrying the reply
 *     is a 422 before anything is stored — never plain text the BD did not choose.
 *
 * The `array` mailer is pinned by phpunit.xml, so assertions read the Symfony message actually handed over.
 */
class EmailDesignedReplyTest extends TestCase
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

    private function user(string $role): User
    {
        $user = User::factory()->create(['status' => 'active', 'name' => 'Jamie Reyes']);
        $user->assignRole($role);

        return $user;
    }

    private function lead(?User $owner = null): Lead
    {
        return Lead::factory()->create([
            'email' => 'prospect@acme.test',
            'contact_name' => 'Maria Santos',
            'business_name' => 'Acme Bakery',
            'assigned_to' => $owner?->id,
        ]);
    }

    private function design(array $attributes = []): EmailDesign
    {
        return EmailDesign::create([
            'name' => 'Brand design',
            'is_default' => false,
            'layout' => $this->layout(
                [$this->logo(), $this->heading(['text' => 'Acme Partners', 'level' => 2])],
                [$this->footer(['content' => $this->doc($this->para($this->text('Vite SEO · Makati')))])],
                signature: ['enabled' => true, 'sign_off' => 'Warm regards,'],
            ),
            ...$attributes,
        ]);
    }

    /** A reply as Lead Details' editor writes it: paragraphs, a mark and a list. */
    private function replyDoc(string $first = 'Thanks for getting back to us, Maria.'): array
    {
        return [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $first]]],
                ['type' => 'paragraph', 'content' => [
                    ['type' => 'text', 'text' => 'Here is '],
                    ['type' => 'text', 'text' => 'what we suggest', 'marks' => [['type' => 'bold']]],
                    ['type' => 'text', 'text' => ':'],
                ]],
                ['type' => 'bulletList', 'content' => [
                    ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'A faster website']]]]],
                ]],
            ],
        ];
    }

    private function payload(array $doc, ?EmailDesign $design = null, array $extra = []): array
    {
        return [
            'subject' => 'Re: Your website',
            'body' => EmailDocument::toText($doc),
            'body_doc' => $doc,
            ...($design !== null ? ['email_design_id' => (string) $design->id] : []),
            ...$extra,
        ];
    }

    private function send(Lead $lead, array $payload)
    {
        return $this->postJson("/api/v1/crm/leads/{$lead->id}/emails", $payload);
    }

    private function preview(Lead $lead, array $doc, EmailDesign|int|string $design)
    {
        return $this->postJson("/api/v1/crm/leads/{$lead->id}/emails/design-preview", [
            'email_design_id' => (string) ($design instanceof EmailDesign ? $design->id : $design),
            'body_doc' => $doc,
        ]);
    }

    private function lastSent(): MimeEmail
    {
        $message = Mail::mailer()->getSymfonyTransport()->messages()->last()?->getOriginalMessage();
        $this->assertInstanceOf(MimeEmail::class, $message, 'Expected an email to have been sent.');

        return $message;
    }

    private function sentCount(): int
    {
        return count(Mail::mailer()->getSymfonyTransport()->messages());
    }

    // ── 1. Preview = send = stored ───────────────────────────────────────────────────────────

    public function test_the_preview_the_sent_html_and_the_stored_body_html_are_identical(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->lead($bd);
        $design = $this->design();
        $doc = $this->replyDoc();

        $previewHtml = $this->preview($lead, $doc, $design)->assertOk()->json('data.html');
        $id = $this->send($lead, $this->payload($doc, $design))->assertCreated()->json('data.id');

        $sentHtml = (string) $this->lastSent()->getHtmlBody();
        $email = Email::findOrFail($id);

        $this->assertIsString($previewHtml);
        $this->assertSame($previewHtml, $sentHtml, 'The preview must be the HTML the transport received.');
        $this->assertSame($sentHtml, $email->body_html, 'body_html must be the HTML the transport received.');
        $this->assertSame($design->id, $email->email_design_id);
        $this->assertSame((string) $design->id, $this->getJson("/api/v1/crm/leads/{$lead->id}/emails")->json('data.0.emailDesignId'));
    }

    public function test_the_designed_reply_is_the_renderers_own_output_with_the_reply_as_content(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->lead($bd);
        $design = $this->design();
        $doc = $this->replyDoc();

        $this->send($lead, $this->payload($doc, $design))->assertCreated();

        $designs = app(EmailTemplateDesignService::class);
        $expected = app(EmailDesignRenderer::class)->toHtml($design->layout, $designs->replyContent($doc), $designs->sendContext($lead, $bd));

        $this->assertSame($expected, (string) $this->lastSent()->getHtmlBody(), 'One renderer: no second rendering path.');
    }

    public function test_the_design_frames_the_reply_header_signature_and_footer_included(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->lead($bd);

        $this->send($lead, $this->payload($this->replyDoc(), $this->design()))->assertCreated();

        $html = (string) $this->lastSent()->getHtmlBody();
        foreach (['Acme Partners' => 'design header', 'Thanks for getting back to us, Maria.' => 'reply text', '<strong>what we suggest</strong>' => 'reply formatting',
            'A faster website' => 'reply list', 'Warm regards,' => 'design sign-off', 'Jamie Reyes' => 'sender in the signature', 'Vite SEO · Makati' => 'design footer'] as $needle => $what) {
            $this->assertStringContainsString($needle, $html, "The designed reply lost its {$what}.");
        }
    }

    public function test_the_plain_text_part_is_the_designs_text_form_and_body_stays_the_reply(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->lead($bd);
        $design = $this->design();
        $doc = $this->replyDoc();

        $id = $this->send($lead, $this->payload($doc, $design))->assertCreated()->json('data.id');

        $designs = app(EmailTemplateDesignService::class);
        $expectedText = app(EmailDesignRenderer::class)->toText($design->layout, $designs->replyContent($doc), $designs->sendContext($lead, $bd));
        $text = (string) $this->lastSent()->getTextBody();

        $this->assertSame($expectedText, $text);
        $this->assertStringContainsString('Warm regards,', $text, 'The plain-text part must carry the design signature.');
        $this->assertStringContainsString('Jamie Reyes', $text);
        $this->assertStringContainsString('Thanks for getting back to us, Maria.', $text);
        // CRM lists and notifications keep showing what the BD wrote.
        $this->assertSame(EmailDocument::toText($doc), Email::findOrFail($id)->body);
    }

    public function test_a_reply_is_sent_literally_and_escaped_inside_the_design(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->lead($bd);

        $this->send($lead, $this->payload($this->replyDoc('Tags like <script>alert(1)</script> & "quotes" stay text.'), $this->design()))->assertCreated();

        $html = (string) $this->lastSent()->getHtmlBody();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;quotes&quot; stay text.', $html);
    }

    // ── 2. No design changes nothing ─────────────────────────────────────────────────────────

    public function test_a_reply_without_a_design_is_sent_exactly_as_before(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->lead($bd);
        $this->design(); // a design existing in the org must not change a reply that did not choose it
        $doc = $this->replyDoc();

        $id = $this->send($lead, $this->payload($doc))->assertCreated()->json('data.id');

        $message = $this->lastSent();
        $email = Email::findOrFail($id);
        $this->assertSame(EmailDocument::toHtml($doc), (string) $message->getHtmlBody());
        $this->assertSame(EmailDocument::toText($doc), (string) $message->getTextBody());
        $this->assertSame(EmailDocument::toHtml($doc), $email->body_html);
        $this->assertNull($email->email_design_id);
        $this->assertNull($this->getJson("/api/v1/crm/leads/{$lead->id}/emails")->json('data.0.emailDesignId'));
    }

    public function test_a_null_design_is_the_same_as_no_design(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->lead($bd);
        $doc = $this->replyDoc();

        $this->send($lead, $this->payload($doc, extra: ['email_design_id' => null]))->assertCreated();

        $this->assertSame(EmailDocument::toHtml($doc), (string) $this->lastSent()->getHtmlBody());
    }

    // ── 3. A chosen design is never silently dropped ─────────────────────────────────────────

    public function test_placeholders_are_refused_in_a_designed_reply_and_nothing_is_stored(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->lead($bd);
        $design = $this->design();

        foreach (['Hi {{first_name}}', 'Hi {{nope}}', 'A lone {{ brace', 'A lone }} brace'] as $text) {
            $doc = $this->replyDoc($text);
            $this->send($lead, $this->payload($doc, $design))->assertUnprocessable()
                ->assertJsonValidationErrors(['body_doc' => 'Placeholders like {{first_name}}']);
            $this->preview($lead, $doc, $design)->assertUnprocessable()->assertJsonValidationErrors('body_doc');
        }

        $this->assertSame(0, Email::count());
        $this->assertSame(0, $this->sentCount());
    }

    public function test_placeholders_are_still_sent_literally_without_a_design(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->lead($bd);

        $this->send($lead, $this->payload($this->replyDoc('Hi {{first_name}}')))->assertCreated();

        $this->assertStringContainsString('Hi {{first_name}}', (string) $this->lastSent()->getHtmlBody());
    }

    public function test_a_reply_over_the_designs_5000_character_limit_is_refused(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->lead($bd);
        $design = $this->design();

        $atLimit = $this->doc($this->para($this->text(str_repeat('a', 5000))));
        $over = $this->doc($this->para($this->text(str_repeat('a', 5001))));

        $this->preview($lead, $atLimit, $design)->assertOk();
        $this->send($lead, $this->payload($over, $design))->assertUnprocessable()
            ->assertJsonValidationErrors(['body_doc' => 'A designed reply can be at most 5,000 characters']);
        $this->preview($lead, $over, $design)->assertUnprocessable()
            ->assertJsonValidationErrors(['body_doc' => 'A designed reply can be at most 5,000 characters']);
        $this->assertSame(0, Email::count());

        // Without a design the same reply sends, as it always has.
        $this->send($lead, $this->payload($over))->assertCreated();
    }

    public function test_a_designed_reply_too_large_for_gmail_is_refused_not_downgraded(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->lead($bd);
        // A design that is valid on its own but whose header escapes to ~98 KB of HTML (each `&` is written
        // as `&amp;`), so an ordinary reply in it outgrows the Gmail guard.
        $header = [];
        for ($i = 0; $i < 4; $i++) {
            $header[] = $this->textBlock(['id' => "amp-{$i}", 'content' => $this->doc($this->para($this->text(str_repeat('&', 4900))))]);
        }
        $design = EmailDesign::create(['name' => 'Heavy', 'is_default' => false, 'layout' => $this->layout($header)]);
        $doc = $this->replyDoc();

        $this->send($lead, $this->payload($doc, $design))->assertUnprocessable()
            ->assertJsonValidationErrors(['email_design_id' => EmailTemplateDesignService::REPLY_TOO_LARGE]);
        $this->preview($lead, $doc, $design)->assertUnprocessable()
            ->assertJsonValidationErrors(['email_design_id' => EmailTemplateDesignService::REPLY_TOO_LARGE]);
        $this->assertSame(0, Email::count());
        $this->assertSame(0, $this->sentCount());
    }

    public function test_a_design_from_another_organization_or_a_deleted_one_is_refused(): void
    {
        $theirOrg = app(OrgOnboardingService::class)->createOrg(
            'Org theirs', 'Owner theirs', 'owner-theirs@client.ph', 'password-123', ['crm' => 'full'],
        )['org'];
        $theirs = CurrentOrg::runAs($theirOrg->id, fn (): EmailDesign => EmailDesign::create(['name' => 'Theirs', 'is_default' => false, 'layout' => $this->layout()]));
        CurrentOrg::clear();

        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->lead($bd);
        $deleted = $this->design(['name' => 'Retired']);
        $deleted->delete();
        $doc = $this->replyDoc();

        foreach ([$theirs->id, $deleted->id, 999999] as $id) {
            $this->send($lead, $this->payload($doc, extra: ['email_design_id' => (string) $id]))->assertUnprocessable()->assertJsonValidationErrors('email_design_id');
            $this->preview($lead, $doc, $id)->assertUnprocessable()->assertJsonValidationErrors('email_design_id');
        }
        $this->assertSame(0, Email::count());
    }

    public function test_a_design_needs_a_reply_document(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->lead($bd);
        $design = $this->design();

        $this->send($lead, ['subject' => 'Hi', 'body' => 'Plain text', 'email_design_id' => (string) $design->id])
            ->assertUnprocessable()->assertJsonValidationErrors('body_doc');
        $this->postJson("/api/v1/crm/leads/{$lead->id}/emails/design-preview", ['email_design_id' => (string) $design->id])
            ->assertUnprocessable()->assertJsonValidationErrors('body_doc');
        $this->assertSame(0, Email::count());
    }

    public function test_a_designed_reply_is_send_now_only_and_never_also_a_template_send(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->lead($bd);
        $design = $this->design();
        $template = EmailTemplate::create([
            'purpose' => 'Follow-up', 'tone' => 'Formal', 'subject' => 'Hi', 'body' => 'Hi', 'is_active' => true,
        ]);
        $doc = $this->replyDoc();

        $this->send($lead, $this->payload($doc, $design, ['scheduled_at' => now()->addDay()->toIso8601String()]))
            ->assertUnprocessable()->assertJsonValidationErrors('email_design_id');
        $this->send($lead, $this->payload($doc, $design, ['email_template_id' => (string) $template->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('email_design_id');
        $this->assertSame(0, Email::count());
    }

    public function test_a_stored_layout_that_no_longer_validates_is_refused(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->lead($bd);
        $design = $this->design();
        // Written past the application, as a bad migration or manual edit would.
        EmailDesign::query()->whereKey($design->id)->update(['layout' => json_encode(['version' => 99])]);

        $this->send($lead, $this->payload($this->replyDoc(), $design))->assertUnprocessable()
            ->assertJsonValidationErrors(['email_design_id' => EmailTemplateDesignService::REPLY_DESIGN_UNUSABLE]);
        $this->assertSame(0, Email::count());
    }

    // ── Access ───────────────────────────────────────────────────────────────────────────────

    public function test_a_bd_lists_design_names_only_and_still_cannot_manage_designs(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $this->design(['name' => 'Zeta', 'is_default' => false]);
        $this->design(['name' => 'Alpha', 'is_default' => false]);
        $default = $this->design(['name' => 'House style', 'is_default' => true]);
        $this->design(['name' => 'Gone'])->delete();

        $data = $this->getJson('/api/v1/crm/email-designs/options')->assertOk()->json('data');
        $this->assertSame(['House style', 'Alpha', 'Zeta'], array_column($data, 'name'), 'Default first, then by name; deleted designs never listed.');
        $this->assertSame(['id', 'name', 'isDefault'], array_keys($data[0]), 'Names only — never a layout.');
        $this->assertSame((string) $default->id, $data[0]['id']);

        $this->getJson('/api/v1/crm/settings/email-designs')->assertForbidden();
        $this->postJson('/api/v1/crm/settings/email-designs', ['name' => 'Mine', 'layout' => json_encode($this->layout())])->assertForbidden();
        $this->patchJson("/api/v1/crm/settings/email-designs/{$default->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->deleteJson("/api/v1/crm/settings/email-designs/{$default->id}")->assertForbidden();
        $this->assertSame('House style', $default->fresh()->name);
    }

    public function test_the_options_never_list_another_organizations_designs(): void
    {
        $theirOrg = app(OrgOnboardingService::class)->createOrg(
            'Org theirs', 'Owner theirs', 'owner-theirs@client.ph', 'password-123', ['crm' => 'full'],
        )['org'];
        CurrentOrg::runAs($theirOrg->id, fn () => EmailDesign::create(['name' => 'Their secret design', 'is_default' => true, 'layout' => $this->layout()]));
        CurrentOrg::clear();
        Sanctum::actingAs($this->user('crm_bd'));
        $this->design(['name' => 'Ours']);

        $this->assertSame(['Ours'], array_column($this->getJson('/api/v1/crm/email-designs/options')->assertOk()->json('data'), 'name'));
    }

    public function test_the_preview_obeys_the_lead_view_policy_and_writes_nothing(): void
    {
        $bd = $this->user('crm_bd');
        $otherBd = $this->user('crm_bd');
        $theirLead = $this->lead($otherBd);
        $design = $this->design();

        Sanctum::actingAs($bd);
        $this->preview($theirLead, $this->replyDoc(), $design)->assertForbidden();

        $ownLead = $this->lead($bd);
        $this->preview($ownLead, $this->replyDoc(), $design)->assertOk();
        $this->assertSame(0, Email::count());
        $this->assertSame(0, $this->sentCount());
    }

    public function test_people_without_the_reply_permission_cannot_list_or_preview(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->lead($bd);
        $design = $this->design();
        Sanctum::actingAs($this->user('hr_employee'));

        $this->getJson('/api/v1/crm/email-designs/options')->assertForbidden();
        $this->preview($lead, $this->replyDoc(), $design)->assertForbidden();
    }
}
