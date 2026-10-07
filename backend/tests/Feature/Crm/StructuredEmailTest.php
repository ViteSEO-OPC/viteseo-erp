<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Jobs\SendScheduledEmailsJob;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\EmailDesign;
use App\Modules\Crm\Models\EmailTemplate;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Outreach\EmailTemplateDesignService;
use App\Services\OrgOnboardingService;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Mime\Email as MimeEmail;
use Tests\TestCase;
use Tests\Unit\Crm\EmailDesign\BuildsEmailDesigns;

/**
 * Lead Details → New designed email: a designed template's content copied onto one lead's draft, edited for that
 * lead, previewed, sent and scheduled — while the template every other lead is offered is never written.
 */
class StructuredEmailTest extends TestCase
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

    private function user(string $role, string $name = 'Uriel Papa'): User
    {
        $user = User::factory()->create(['status' => 'active', 'name' => $name, 'phone' => '0945 449 3862']);
        $user->assignRole($role);

        return $user;
    }

    private function lead(?User $owner = null): Lead
    {
        return Lead::factory()->create([
            'email' => 'mj@gcamp.test', 'contact_name' => 'MJ Reyes', 'business_name' => 'GCamp', 'assigned_to' => $owner?->id,
        ]);
    }

    private function design(string $name = 'Proposal'): EmailDesign
    {
        return EmailDesign::create(['name' => $name, 'is_default' => false, 'layout' => $this->layout([], [], ['link_underline' => false], ['show_phone' => true])]);
    }

    /** @return array<string, mixed> */
    private function proposalContent(): array
    {
        return $this->content([
            $this->heading(['id' => 'title', 'text' => 'Your proposal is ready.', 'font_size' => 36, 'align' => 'center']),
            $this->textBlock(['id' => 'hi', 'content' => $this->doc($this->para($this->text('Hi {{first_name}},')))]),
            $this->card(['id' => 'fees', 'eyebrow' => 'Our fees, for our work', 'title' => 'Three options.', 'blocks' => [
                $this->rows(['id' => 'options', 'items' => [
                    ['title' => 'Essential · ₱50,000', 'description' => 'The app, the video library and the player.'],
                    ['title' => 'Standard · ₱65,000', 'description' => 'Adds viewer sign-in.'],
                ]]),
            ]]),
            $this->actions(['id' => 'closing']),
        ]);
    }

    private function template(?EmailDesign $design = null, array $attributes = []): EmailTemplate
    {
        $design ??= $this->design();
        $content = $attributes['content'] ?? $this->proposalContent();

        return EmailTemplate::create([
            'purpose' => 'Follow-up',
            'tone' => 'Warm & professional',
            'subject' => 'Your proposal, {{first_name}}',
            'body' => app(EmailTemplateDesignService::class)->plainTextBody($design->layout, $content),
            'content' => $content,
            'email_design_id' => $design->id,
            'is_active' => true,
            ...$attributes,
        ]);
    }

    private function start(Lead $lead, EmailTemplate|int $template)
    {
        return $this->postJson("/api/v1/crm/leads/{$lead->id}/emails/structured", ['email_template_id' => (string) ($template instanceof EmailTemplate ? $template->id : $template)]);
    }

    private function save(string $emailId, array $content, EmailDesign|int $design, array $extra = [])
    {
        return $this->putJson("/api/v1/crm/emails/{$emailId}/structured", [
            'subject' => 'Your proposal, MJ',
            'email_design_id' => (string) ($design instanceof EmailDesign ? $design->id : $design),
            'content' => json_encode($content),
            ...$extra,
        ]);
    }

    private function preview(Lead $lead, array $content, EmailDesign|int $design)
    {
        return $this->postJson("/api/v1/crm/leads/{$lead->id}/emails/structured-preview", [
            'email_design_id' => (string) ($design instanceof EmailDesign ? $design->id : $design),
            'content' => json_encode($content),
            'subject' => 'Your proposal, {{first_name}}',
        ]);
    }

    /** The content with its first row's title changed, as a BD would change one price. */
    private function withFirstPrice(array $content, string $title): array
    {
        $content['blocks'][2]['blocks'][0]['items'][0]['title'] = $title;

        return $content;
    }

    private function messages(): int
    {
        return Mail::mailer()->getSymfonyTransport()->messages()->count();
    }

    private function lastSent(): MimeEmail
    {
        $message = Mail::mailer()->getSymfonyTransport()->messages()->last()?->getOriginalMessage();
        $this->assertInstanceOf(MimeEmail::class, $message);

        return $message;
    }

    // ── Copy ─────────────────────────────────────────────────────────────────────────────────

    public function test_starting_copies_the_templates_content_design_and_subject_onto_a_new_draft(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->lead($bd);
        $template = $this->template();

        $data = $this->start($lead, $template)->assertCreated()->json('data');
        $email = Email::query()->findOrFail($data['id']);

        $this->assertSame('draft', $email->status);
        $this->assertSame($template->content, $email->content);
        $this->assertSame($template->id, $email->email_template_id);
        $this->assertSame($template->email_design_id, $email->email_design_id);
        $this->assertSame('Your proposal, MJ', $email->subject, 'The subject is filled in for this lead.');
        $this->assertSame($bd->id, $email->user_id);
        $this->assertStringContainsString('Essential · ₱50,000', $email->body, 'body is the plain text of the content.');
        $this->assertSame($data['content'], $template->content);
        $this->assertSame(0, $this->messages(), 'Starting a draft sends nothing.');
    }

    public function test_only_an_active_designed_template_of_this_organization_can_be_started(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->lead($bd);

        $this->start($lead, $this->template(attributes: ['is_active' => false]))->assertUnprocessable()->assertJsonValidationErrors('email_template_id');
        $plain = EmailTemplate::create(['purpose' => 'Introduction', 'tone' => 'Formal', 'subject' => 'Hi', 'body' => 'Hi', 'is_active' => true]);
        $this->start($lead, $plain)->assertUnprocessable()->assertJsonValidationErrors('email_template_id');
        $trashed = $this->template();
        $trashed->delete();
        $this->start($lead, $trashed)->assertUnprocessable()->assertJsonValidationErrors('email_template_id');

        $theirOrg = app(OrgOnboardingService::class)->createOrg('Org theirs', 'Owner theirs', 'owner-theirs@client.ph', 'password-123', ['crm' => 'full'])['org'];
        $theirs = CurrentOrg::runAs($theirOrg->id, fn () => $this->template($this->design('Theirs')));
        CurrentOrg::clear();
        Sanctum::actingAs($bd);
        $this->start($lead, $theirs->id)->assertUnprocessable()->assertJsonValidationErrors('email_template_id');

        $this->assertSame(0, Email::query()->count());
    }

    // ── Isolation ────────────────────────────────────────────────────────────────────────────

    public function test_editing_the_lead_copy_never_changes_the_master_template(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->lead($bd);
        $template = $this->template();
        $before = DB::table('crm_email_templates')->where('id', $template->id)->first();

        $id = $this->start($lead, $template)->json('data.id');
        $copy = Email::query()->findOrFail($id)->content;
        $this->assertStringContainsString('Essential · ₱50,000</p>', $this->preview($lead, $copy, $template->design)->assertOk()->json('data.html'));

        $changed = $this->withFirstPrice($copy, 'Essential · ₱55,000');
        $this->save($id, $changed, $template->design)->assertOk();
        $html = $this->preview($lead, $changed, $template->design)->assertOk()->json('data.html');

        $this->assertStringContainsString('Essential · ₱55,000</p>', $html);
        $this->assertStringNotContainsString('₱50,000', $html);
        $this->assertSame('Essential · ₱55,000', Email::query()->findOrFail($id)->content['blocks'][2]['blocks'][0]['items'][0]['title']);
        $this->assertEquals($before, DB::table('crm_email_templates')->where('id', $template->id)->first(), 'The master template row is untouched.');
        $this->assertSame('Essential · ₱50,000', $template->fresh()->content['blocks'][2]['blocks'][0]['items'][0]['title']);

        // A second lead started from the same template gets the master's content, not the first lead's edit.
        $other = $this->lead($bd);
        $this->assertSame('Essential · ₱50,000', Email::query()->findOrFail($this->start($other, $template)->json('data.id'))->content['blocks'][2]['blocks'][0]['items'][0]['title']);
    }

    // ── Preview = send = body_html ───────────────────────────────────────────────────────────

    public function test_the_preview_the_sent_html_and_body_html_are_identical(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->lead($bd);
        $template = $this->template();
        $id = $this->start($lead, $template)->json('data.id');
        $content = $this->withFirstPrice(Email::query()->findOrFail($id)->content, 'Essential · ₱55,000');
        $this->save($id, $content, $template->design)->assertOk();

        $preview = $this->preview($lead, $content, $template->design)->assertOk()->json('data');
        $this->postJson("/api/v1/crm/emails/{$id}/send")->assertOk()->assertJsonPath('data.status', 'sent');
        $sent = $this->lastSent();

        $this->assertSame($preview['html'], (string) $sent->getHtmlBody());
        $this->assertSame($preview['html'], Email::query()->findOrFail($id)->body_html);
        $this->assertSame($preview['text'], (string) $sent->getTextBody());
        $this->assertSame('Your proposal, MJ', $preview['subject']);
        $this->assertStringContainsString('<strong>Uriel Papa</strong>', $preview['html'], 'The real sender signs it.');
        $this->assertStringContainsString('Essential · ₱55,000</p>', $preview['html']);
    }

    public function test_body_always_follows_the_saved_content(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->lead($bd);
        $template = $this->template();
        $id = $this->start($lead, $template)->json('data.id');

        $this->save($id, $this->withFirstPrice(Email::query()->findOrFail($id)->content, 'Essential · ₱55,000'), $template->design)->assertOk();
        $body = Email::query()->findOrFail($id)->body;

        $this->assertStringContainsString('Essential · ₱55,000', $body);
        $this->assertStringNotContainsString('₱50,000', $body);
    }

    // ── Design switching and validation ──────────────────────────────────────────────────────

    public function test_the_draft_may_switch_to_another_live_design_but_not_a_deleted_or_foreign_one(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->lead($bd);
        $template = $this->template();
        $id = $this->start($lead, $template)->json('data.id');
        $content = Email::query()->findOrFail($id)->content;

        $other = $this->design('Another');
        $this->save($id, $content, $other)->assertOk()->assertJsonPath('data.emailDesignId', (string) $other->id);

        $gone = $this->design('Gone');
        $gone->delete();
        $this->save($id, $content, $gone)->assertUnprocessable()->assertJsonValidationErrors('email_design_id');
        $this->preview($lead, $content, $gone)->assertUnprocessable()->assertJsonValidationErrors('email_design_id');

        $theirOrg = app(OrgOnboardingService::class)->createOrg('Org theirs', 'Owner theirs', 'owner-theirs@client.ph', 'password-123', ['crm' => 'full'])['org'];
        $theirs = CurrentOrg::runAs($theirOrg->id, fn () => $this->design('Theirs'));
        CurrentOrg::clear();
        Sanctum::actingAs($bd);
        $this->save($id, $content, $theirs->id)->assertUnprocessable()->assertJsonValidationErrors('email_design_id');
        $this->assertSame($other->id, Email::query()->findOrFail($id)->email_design_id);
    }

    public function test_invalid_content_is_refused_where_it_is_wrong_and_nothing_is_stored(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->lead($bd);
        $template = $this->template();
        $id = $this->start($lead, $template)->json('data.id');
        $copy = Email::query()->findOrFail($id)->content;

        $badUrl = $copy;
        $badUrl['blocks'][3]['items'][0]['url'] = 'javascript:alert(1)';
        $this->save($id, $badUrl, $template->design)->assertUnprocessable()->assertJsonValidationErrors('content.blocks.3.items.0.url');
        $this->preview($lead, $badUrl, $template->design)->assertUnprocessable()->assertJsonValidationErrors('content.blocks.3.items.0.url');

        $blankRow = $this->withFirstPrice($copy, '');
        $this->save($id, $blankRow, $template->design)->assertUnprocessable()->assertJsonValidationErrors('content.blocks.2.blocks.0.items.0.title');

        $silent = $this->content([$this->divider()]);
        $this->save($id, $silent, $template->design)->assertUnprocessable()->assertJsonValidationErrors('content');

        $this->putJson("/api/v1/crm/emails/{$id}/structured", ['subject' => 'x', 'email_design_id' => (string) $template->email_design_id, 'content' => '{not json'])->assertUnprocessable();

        $this->assertSame($copy, Email::query()->findOrFail($id)->content, 'A refused save changes nothing.');
    }

    public function test_a_plain_draft_cannot_be_saved_as_structured(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->lead($bd);
        $template = $this->template();
        $plain = $this->postJson("/api/v1/crm/leads/{$lead->id}/emails/drafts", ['subject' => 'Hi', 'body' => 'Hello'])->assertCreated()->json('data.id');

        $this->save($plain, $template->content, $template->design)->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertNull(Email::query()->findOrFail($plain)->content);
    }

    // ── Fail closed ──────────────────────────────────────────────────────────────────────────

    public function test_a_deleted_design_refuses_the_send_instead_of_sending_plain_text(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->lead($bd);
        $template = $this->template();
        $id = $this->start($lead, $template)->json('data.id');
        $template->design->delete();

        $this->postJson("/api/v1/crm/emails/{$id}/send")->assertUnprocessable()->assertJsonValidationErrors('email_design_id');

        $this->assertSame(0, $this->messages());
        $this->assertSame('draft', Email::query()->findOrFail($id)->status);
    }

    public function test_content_that_no_longer_validates_refuses_the_send(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->lead($bd);
        $id = $this->start($lead, $this->template())->json('data.id');
        DB::table('crm_emails')->where('id', $id)->update(['content' => json_encode(['version' => 1, 'blocks' => [['type' => 'script', 'id' => 'x']]])]);

        $this->postJson("/api/v1/crm/emails/{$id}/send")->assertUnprocessable()->assertJsonValidationErrors('content');

        $this->assertSame(0, $this->messages());
        $this->assertNull(Email::query()->findOrFail($id)->body_html);
    }

    // ── Scheduling ───────────────────────────────────────────────────────────────────────────

    public function test_a_scheduled_structured_email_is_delivered_as_the_previewed_bytes(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->lead($bd);
        $template = $this->template();
        $id = $this->start($lead, $template)->json('data.id');
        $content = Email::query()->findOrFail($id)->content;
        $preview = $this->preview($lead, $content, $template->design)->json('data.html');

        $this->postJson("/api/v1/crm/emails/{$id}/schedule", ['scheduled_at' => now()->addDay()->toIso8601String()])
            ->assertOk()->assertJsonPath('data.status', 'scheduled');
        $this->assertSame(0, $this->messages(), 'Scheduling sends nothing.');
        $this->postJson("/api/v1/crm/emails/{$id}/schedule", ['scheduled_at' => now()->addDay()->toIso8601String()])->assertForbidden();

        $this->travel(2)->days();
        SendScheduledEmailsJob::dispatchSync();

        $this->assertSame('sent', Email::withoutGlobalScopes()->findOrFail($id)->status);
        $this->assertSame($preview, Email::withoutGlobalScopes()->findOrFail($id)->body_html);
        $this->assertSame($preview, (string) $this->lastSent()->getHtmlBody());
    }

    public function test_a_scheduled_structured_email_whose_design_is_gone_fails_instead_of_sending_plain_text(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->lead($bd);
        $template = $this->template();
        $id = $this->start($lead, $template)->json('data.id');
        $this->postJson("/api/v1/crm/emails/{$id}/schedule", ['scheduled_at' => now()->addDay()->toIso8601String()])->assertOk();
        $template->design->delete();

        $this->travel(2)->days();
        SendScheduledEmailsJob::dispatchSync();

        $this->assertSame('failed', Email::withoutGlobalScopes()->findOrFail($id)->status);
        $this->assertSame(0, $this->messages());
    }

    public function test_scheduling_needs_a_future_time_and_a_structured_draft(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->lead($bd);
        $id = $this->start($lead, $this->template())->json('data.id');
        $this->postJson("/api/v1/crm/emails/{$id}/schedule", ['scheduled_at' => now()->subMinute()->toIso8601String()])->assertUnprocessable()->assertJsonValidationErrors('scheduled_at');

        $plain = $this->postJson("/api/v1/crm/leads/{$lead->id}/emails/drafts", ['subject' => 'Hi', 'body' => 'Hello'])->json('data.id');
        $this->postJson("/api/v1/crm/emails/{$plain}/schedule", ['scheduled_at' => now()->addDay()->toIso8601String()])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame('draft', Email::query()->findOrFail($plain)->status);
    }

    // ── Permissions ──────────────────────────────────────────────────────────────────────────

    public function test_the_existing_permissions_and_draft_ownership_apply(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->lead($bd);
        $template = $this->template();
        $id = $this->start($lead, $template)->json('data.id');
        $content = Email::query()->findOrFail($id)->content;

        // Another BD: cannot see the lead, cannot start, preview, save, schedule or send this draft.
        Sanctum::actingAs($this->user('crm_bd', 'Other BD'));
        $this->start($lead, $template)->assertForbidden();
        $this->preview($lead, $content, $template->design)->assertForbidden();
        $this->save($id, $content, $template->design)->assertForbidden();
        $this->postJson("/api/v1/crm/emails/{$id}/schedule", ['scheduled_at' => now()->addDay()->toIso8601String()])->assertForbidden();
        $this->postJson("/api/v1/crm/emails/{$id}/send")->assertForbidden();

        // Someone without the reply permission at all.
        Sanctum::actingAs($this->user('hr_employee', 'HR'));
        $this->start($lead, $template)->assertForbidden();
        $this->preview($lead, $content, $template->design)->assertForbidden();

        // An admin may manage the BD's draft, as with every draft.
        Sanctum::actingAs($this->user('crm_admin', 'Admin'));
        $this->save($id, $content, $template->design)->assertOk();

        $this->assertSame(0, $this->messages());
        $this->assertSame('draft', Email::query()->findOrFail($id)->status);
    }

    public function test_a_structured_draft_appears_in_the_authors_drafts_with_its_content(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);
        $lead = $this->lead($bd);
        $id = $this->start($lead, $this->template())->json('data.id');

        $draft = collect($this->getJson('/api/v1/crm/emails?status=draft')->assertOk()->json('data'))->firstWhere('id', $id);

        $this->assertNotNull($draft['content']);
        $this->assertSame('Essential · ₱50,000', $draft['content']['blocks'][2]['blocks'][0]['items'][0]['title']);
    }
}
