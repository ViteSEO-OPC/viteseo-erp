<?php

namespace Tests\Feature\Crm;

use App\Models\Org;
use App\Models\User;
use App\Modules\Crm\Database\Seeders\EmailTemplateSeeder;
use App\Modules\Crm\Models\EmailDesign;
use App\Modules\Crm\Models\EmailTemplate;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Outreach\EmailDesignService;
use App\Modules\Crm\Services\Outreach\EmailTemplateDesignService;
use App\Modules\Crm\Services\Outreach\EmailTemplateManagementService;
use App\Modules\Crm\Support\EmailDesign\EmailDesignRenderer;
use App\Modules\Crm\Support\EmailDesign\EmailDesignSchema;
use App\Modules\Crm\Support\EmailDesign\EmailDesignValidator;
use App\Modules\Crm\Support\EmailTemplateCatalog;
use App\Services\OrgOnboardingService;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\Crm\EmailDesign\BuildsEmailDesigns;

/**
 * Settings → Email Templates, designed: a template's own content shown in a reusable email design, the
 * plain-text body derived from both, the template preview, and the Gmail size guard.
 *
 * The legacy cases matter as much as the new ones. A template without content must behave exactly as Phase 1
 * shipped it, and a designed template must still reach the composer and the automation route through `body`,
 * because neither has been taught about designs.
 */
class EmailTemplateContentTest extends TestCase
{
    use BuildsEmailDesigns;
    use RefreshDatabase;

    private const URL = '/api/v1/crm/settings/email-templates';

    /** The fixture content's stored body in the default design: its text, the signature area, and every placeholder left for TemplateRenderer. */
    private const DERIVED_BODY = "Hi {{first_name}},\n\n"
        ."We would like to help {{business_name}} grow online.\n\n"
        ."Book a call: https://viteseo.ph/book\n\n"
        ."----------------------------------------\n\n"
        ."Best regards,\n{{sender_name}}";

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(EmailTemplateSeeder::class);
    }

    private function user(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(['status' => 'active', ...$attributes]);
        $user->assignRole($role);

        return $user;
    }

    private function actingAsAdmin(): User
    {
        $admin = $this->user('crm_admin');
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function template(string $purpose, string $tone = 'Warm & professional'): EmailTemplate
    {
        return EmailTemplate::query()->where('purpose', $purpose)->where('tone', $tone)->firstOrFail();
    }

    /** @param  array<string, mixed>|null  $content */
    private function contentJson(?array $content = null): string
    {
        return (string) json_encode($content ?? $this->content());
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return [
            'purpose' => 'proposal',
            'tone' => 'Formal',
            'subject' => 'Your proposal, {{first_name}}',
            'content' => $this->contentJson(),
            ...$overrides,
        ];
    }

    private function patchTemplate(EmailTemplate $template, array $body): TestResponse
    {
        return $this->patchJson(self::URL."/{$template->id}", $body);
    }

    private function preview(array $body): TestResponse
    {
        return $this->postJson(self::URL.'/preview', $body);
    }

    private function defaultDesign(): EmailDesign
    {
        return app(EmailDesignService::class)->defaultDesign();
    }

    /** @param  list<array<string, mixed>>  $footer */
    private function campaignDesign(array $footer = []): EmailDesign
    {
        return EmailDesign::create([
            'name' => 'Campaign',
            'is_default' => false,
            'layout' => $this->layout([], $footer, signature: ['sign_off' => 'Warm wishes,']),
        ]);
    }

    /** Content that passes the schema but whose HTML is far too large for Gmail. */
    private function gmailSizedContent(): array
    {
        return $this->content([$this->textBlock(['content' => $this->doc($this->para($this->text('Hello')), ...array_fill(0, 1000, $this->para()))])]);
    }

    private function designedTemplate(string $purpose = 'Follow-up', string $tone = 'Casual', ?EmailDesign $design = null): EmailTemplate
    {
        $template = $this->template($purpose, $tone);
        $body = ['content' => $this->contentJson()];
        if ($design !== null) {
            $body['email_design_id'] = $design->id;
        }
        $this->patchTemplate($template, $body)->assertOk();

        return $template->fresh();
    }

    /** A fixture block with its colors removed, so each one inherits. */
    private function uncolored(array $block): array
    {
        return array_diff_key($block, array_flip(['color', 'background_color', 'text_color']));
    }

    // ── Schema & legacy ───────────────────────────────────────────────────────────────────────

    public function test_the_new_columns_exist_and_every_existing_template_stays_plain(): void
    {
        $this->actingAsAdmin();

        $this->assertTrue(Schema::hasColumns('crm_email_templates', ['content', 'email_design_id']));
        $this->assertSame(0, EmailTemplate::query()->whereNotNull('content')->orWhereNotNull('email_design_id')->count());

        foreach ($this->getJson(self::URL)->assertOk()->json('data') as $row) {
            $this->assertNull($row['content']);
            $this->assertNull($row['emailDesignId']);
            $this->assertNull($row['emailDesignName']);
            $this->assertFalse($row['isDesigned']);
        }
    }

    public function test_without_content_a_template_is_created_exactly_as_before(): void
    {
        $this->actingAsAdmin();
        $body = "Dear {{contact_name}},\n\nThe proposal is attached.";

        $this->postJson(self::URL, $this->payload(['content' => null, 'body' => $body]))
            ->assertCreated()
            ->assertJsonPath('data.isDesigned', false)
            ->assertJsonPath('data.content', null)
            ->assertJsonPath('data.emailDesignId', null)
            ->assertJsonPath('data.body', $body);

        $withoutKey = $this->payload(['tone' => 'Casual', 'body' => $body]);
        unset($withoutKey['content']);
        $this->postJson(self::URL, $withoutKey)->assertCreated()->assertJsonPath('data.body', $body);

        $this->assertNull($this->template('proposal', 'Casual')->content);
        $this->assertSame(0, EmailDesign::query()->count(), 'a plain template never creates a design');
    }

    public function test_a_plain_template_still_requires_a_body(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::URL, $this->payload(['content' => null]))->assertJsonValidationErrors('body');
    }

    public function test_a_plain_templates_body_is_still_edited_directly(): void
    {
        $this->actingAsAdmin();
        $template = $this->template('Follow-up', 'Casual');

        $this->patchTemplate($template, ['body' => 'Still plain, {{first_name}}'])
            ->assertOk()
            ->assertJsonPath('data.body', 'Still plain, {{first_name}}')
            ->assertJsonPath('data.isDesigned', false);
    }

    public function test_only_templates_given_content_have_a_design(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::URL, $this->payload())->assertCreated();
        $this->designedTemplate('Follow-up', 'Casual');
        $this->patchTemplate($this->template('Follow-up', 'Formal'), ['subject' => 'Still plain, {{first_name}}'])->assertOk();

        $designed = EmailTemplate::query()->whereNotNull('content')->get()
            ->map(fn (EmailTemplate $template): string => $template->purpose.' · '.$template->tone)
            ->sort()->values()->all();

        $this->assertSame(['Follow-up · Casual', 'proposal · Formal'], $designed);
        $this->assertSame(0, EmailTemplate::query()->whereNull('content')->whereNotNull('email_design_id')->count());
    }

    // ── Creating designed templates ───────────────────────────────────────────────────────────

    public function test_a_designed_template_uses_the_default_design_and_its_body_is_derived(): void
    {
        $admin = $this->actingAsAdmin();

        $response = $this->postJson(self::URL, $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.isDesigned', true)
            ->assertJsonPath('data.content', $this->content())
            ->assertJsonPath('data.body', self::DERIVED_BODY);

        $default = $this->defaultDesign();
        $response->assertJsonPath('data.emailDesignId', (string) $default->id)->assertJsonPath('data.emailDesignName', $default->name);

        $template = $this->template('proposal', 'Formal');
        $this->assertSame($this->content(), $template->content);
        $this->assertSame($default->id, $template->email_design_id);
        $this->assertSame($admin->id, $template->created_by);
    }

    public function test_a_designed_template_can_name_another_design(): void
    {
        $this->actingAsAdmin();
        $campaign = $this->campaignDesign();

        $this->postJson(self::URL, $this->payload(['email_design_id' => $campaign->id]))
            ->assertCreated()
            ->assertJsonPath('data.emailDesignId', (string) $campaign->id)
            ->assertJsonPath('data.emailDesignName', 'Campaign')
            ->assertJsonPath('data.body', str_replace('Best regards,', 'Warm wishes,', self::DERIVED_BODY));
    }

    public function test_an_unknown_design_is_refused(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::URL, $this->payload(['email_design_id' => 999999]))
            ->assertJsonValidationErrors(['email_design_id' => EmailTemplateManagementService::UNKNOWN_DESIGN]);
        $this->postJson(self::URL, $this->payload(['email_design_id' => 'default']))->assertJsonValidationErrors('email_design_id');

        $this->assertFalse(EmailTemplate::query()->where('purpose', 'proposal')->where('tone', 'Formal')->exists());
    }

    public function test_a_design_is_only_for_a_template_with_content(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::URL, $this->payload(['content' => null, 'body' => 'Plain', 'email_design_id' => $this->defaultDesign()->id]))
            ->assertJsonValidationErrors(['email_design_id' => EmailTemplateManagementService::DESIGN_ONLY_FOR_DESIGNED]);
    }

    public function test_a_body_sent_with_content_is_dropped_without_being_validated(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::URL, $this->payload(['body' => 'Call me at {{phone}}']))
            ->assertCreated()
            ->assertJsonPath('data.body', self::DERIVED_BODY);
    }

    public function test_the_content_must_be_a_json_encoded_string(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::URL, $this->payload(['content' => $this->content()]))
            ->assertJsonValidationErrors(['content' => 'Send the template content as a JSON-encoded string.']);
    }

    public function test_json_that_does_not_decode_is_refused(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::URL, $this->payload(['content' => '{"version":1,']))
            ->assertJsonValidationErrors(['content' => EmailDesignValidator::MALFORMED]);
    }

    public function test_a_content_problem_is_reported_at_its_path_and_nothing_is_saved(): void
    {
        $this->actingAsAdmin();
        $count = EmailTemplate::withTrashed()->count();

        $this->postJson(self::URL, $this->payload(['content' => $this->contentJson($this->content([$this->button(['url' => 'javascript:alert(1)'])]))]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content.blocks.0.url' => EmailDesignValidator::UNSAFE_URL]);

        $this->assertSame($count, EmailTemplate::withTrashed()->count());
    }

    public function test_a_placeholder_outside_the_catalog_is_refused_inside_content(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::URL, $this->payload(['content' => $this->contentJson($this->content([$this->heading(['text' => 'From {{sender_title}}'])]))]))
            ->assertJsonValidationErrors(['content.blocks.0.text' => EmailDesignValidator::UNKNOWN_PLACEHOLDER]);
    }

    public function test_oversized_or_too_deep_content_is_refused(): void
    {
        $this->actingAsAdmin();

        $oversized = $this->content([$this->heading(['text' => str_repeat('a', EmailDesignSchema::MAX_BYTES)])]);
        $this->postJson(self::URL, $this->payload(['content' => $this->contentJson($oversized)]))
            ->assertJsonValidationErrors(['content' => EmailDesignValidator::TOO_LARGE]);

        $this->postJson(self::URL, $this->payload(['content' => str_repeat('[', 40).str_repeat(']', 40)]))
            ->assertJsonValidationErrors(['content' => EmailDesignValidator::TOO_DEEP]);
    }

    public function test_the_subject_placeholders_are_still_checked_for_a_designed_template(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::URL, $this->payload(['subject' => 'Hi {{FirstName}}']))->assertJsonValidationErrors('subject');
    }

    public function test_the_purpose_and_tone_rules_still_apply_to_a_designed_template(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::URL, $this->payload(['purpose' => 'Follow-up', 'tone' => 'Casual']))->assertJsonValidationErrors('tone');
        $this->postJson(self::URL, $this->payload(['purpose' => 'Cold pitch']))->assertJsonValidationErrors('purpose');
    }

    public function test_spaces_between_formatted_words_survive_the_request(): void
    {
        $this->actingAsAdmin();
        $block = $this->textBlock(['content' => $this->doc($this->para($this->text('Hello '), $this->text('world', [['type' => 'bold']]), $this->text(' again ')))]);

        // Outer whitespace around the JSON is trimmed by the framework and ignored by JSON.
        $this->postJson(self::URL, $this->payload(['content' => '  '.$this->contentJson($this->content([$block]))."\n"]))->assertCreated();

        $template = $this->template('proposal', 'Formal');
        $this->assertSame(['Hello ', 'world', ' again '], array_column($template->content['blocks'][0]['content']['content'][0]['content'], 'text'));
        $this->assertStringStartsWith("Hello world again\n\nBest regards,", $template->body);
    }

    // ── Guards ────────────────────────────────────────────────────────────────────────────────

    public function test_content_too_large_for_gmail_is_refused(): void
    {
        $this->actingAsAdmin();
        $content = $this->gmailSizedContent();

        $this->assertNull((new EmailDesignValidator)->contentProblem($content));
        $this->assertLessThan(EmailDesignSchema::MAX_BYTES / 2, strlen($this->contentJson($content)));
        $this->assertGreaterThan(EmailTemplateDesignService::MAX_HTML_BYTES, app(EmailTemplateDesignService::class)->htmlBytes($this->defaultDesign()->layout, $content));

        $this->postJson(self::URL, $this->payload(['content' => $this->contentJson($content)]))
            ->assertJsonValidationErrors(['content' => EmailTemplateDesignService::HTML_TOO_LARGE]);
    }

    public function test_the_gmail_guard_leaves_headroom_below_gmails_limit(): void
    {
        $this->assertLessThan(102_000, EmailTemplateDesignService::MAX_HTML_BYTES);
    }

    public function test_the_gmail_guard_measures_images_and_the_logo_at_full_url_length(): void
    {
        $service = app(EmailTemplateDesignService::class);
        $withoutImages = $service->htmlBytes($this->layout([], []), $this->content([$this->heading()]));
        $withImages = $service->htmlBytes($this->layout([$this->logo()], []), $this->content([$this->heading(), $this->image()]));

        $this->assertGreaterThan($withoutImages + 2 * strlen(self::ASSET_URL), $withImages);
    }

    public function test_content_whose_plain_text_would_not_fit_the_body_column_is_refused(): void
    {
        $this->actingAsAdmin();
        $long = $this->doc($this->para($this->text(str_repeat('a', EmailDesignSchema::MAX_BLOCK_TEXT))));
        $content = $this->content([
            $this->textBlock(['id' => 'one', 'content' => $long]),
            $this->textBlock(['id' => 'two', 'content' => $long]),
            $this->textBlock(['id' => 'three', 'content' => $long]),
        ]);

        $this->postJson(self::URL, $this->payload(['content' => $this->contentJson($content)]))
            ->assertJsonValidationErrors(['content' => EmailTemplateDesignService::TEXT_TOO_LONG]);
    }

    /** @return array<string, array{list<array{string, array<string, mixed>}>, array<string, mixed>}> */
    public static function contentWithoutReadableText(): array
    {
        $invisible = "\u{00A0} \u{200B}\u{00A0}";

        return [
            'divider only' => [[['divider', []]], []],
            'spacer only' => [[['spacer', []]], []],
            'divider and spacer' => [[['divider', []], ['spacer', []]], []],
            'layout and images only' => [[['spacer', []], ['image', ['alt' => 'Our work']], ['divider', []]], []],
            'a heading of non-breaking and zero-width spaces' => [[['heading', ['text' => $invisible]], ['divider', []]], []],
            'text of non-breaking and zero-width spaces' => [[['text', ['content' => ['type' => 'doc', 'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $invisible], ['type' => 'hardBreak']]],
                ['type' => 'paragraph'],
            ]]]], ['spacer', []]], []],
            'a sign-off override with no content' => [[['spacer', []]], ['sign_off' => 'Cheers,', 'preheader' => 'Hello']],
        ];
    }

    /**
     * @param  list<array{string, array<string, mixed>}>  $blocks
     * @param  array<string, mixed>  $extra
     */
    #[DataProvider('contentWithoutReadableText')]
    public function test_content_without_readable_text_is_refused(array $blocks, array $extra): void
    {
        $this->actingAsAdmin();
        $content = $this->contentJson($this->content(array_map(fn (array $block) => $this->block(...$block), $blocks), $extra));

        $this->postJson(self::URL, $this->payload(['content' => $content]))
            ->assertJsonValidationErrors(['content' => EmailTemplateDesignService::NO_READABLE_CONTENT]);
        $this->preview(['content' => $content])
            ->assertJsonValidationErrors(['content' => EmailTemplateDesignService::NO_READABLE_CONTENT]);
    }

    /** @return array<string, array{list<array{string, array<string, mixed>}>}> */
    public static function contentWithReadableText(): array
    {
        return [
            'meaningful text and a divider' => [[['text', []], ['divider', []]]],
            'a meaningful heading and a spacer' => [[['heading', ['text' => 'Welcome']], ['spacer', []]]],
            'a button with a meaningful label' => [[['button', ['label' => 'Book a call']]]],
            'a heading that is a placeholder' => [[['heading', ['text' => '{{business_name}}']]]],
        ];
    }

    /** @param  list<array{string, array<string, mixed>}>  $blocks */
    #[DataProvider('contentWithReadableText')]
    public function test_content_with_readable_text_is_accepted(array $blocks): void
    {
        $this->actingAsAdmin();
        $content = $this->contentJson($this->content(array_map(fn (array $block) => $this->block(...$block), $blocks)));

        $this->preview(['content' => $content])->assertOk();
        $this->postJson(self::URL, $this->payload(['content' => $content]))->assertCreated()->assertJsonPath('data.isDesigned', true);
    }

    // ── Editing designed templates ────────────────────────────────────────────────────────────

    public function test_a_plain_template_can_be_given_content(): void
    {
        $this->actingAsAdmin();
        $template = $this->template('Follow-up', 'Casual');

        $this->patchTemplate($template, ['content' => $this->contentJson()])
            ->assertOk()
            ->assertJsonPath('data.isDesigned', true)
            ->assertJsonPath('data.emailDesignId', (string) $this->defaultDesign()->id)
            ->assertJsonPath('data.body', self::DERIVED_BODY)
            ->assertJsonPath('data.subject', $template->subject);
    }

    public function test_replacing_content_derives_the_body_again(): void
    {
        $this->actingAsAdmin();
        $template = $this->designedTemplate();

        $this->patchTemplate($template, ['content' => $this->contentJson($this->content([$this->heading(['text' => 'Now in {{city}}'])])), 'body' => 'ignored'])
            ->assertOk()
            ->assertJsonPath('data.body', "Now in {{city}}\n\nBest regards,\n{{sender_name}}");
    }

    public function test_a_designed_templates_body_cannot_be_edited_on_its_own(): void
    {
        $this->actingAsAdmin();
        $template = $this->designedTemplate();

        $this->patchTemplate($template, ['body' => 'Hand-written'])
            ->assertJsonValidationErrors(['body' => EmailTemplateManagementService::BODY_IS_DERIVED]);

        $this->assertSame(self::DERIVED_BODY, $template->fresh()->body);
    }

    public function test_a_designed_template_keeps_its_content_when_other_fields_change(): void
    {
        $this->actingAsAdmin();
        $template = $this->designedTemplate();

        $this->patchTemplate($template, ['subject' => 'Checking in, {{first_name}}', 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.subject', 'Checking in, {{first_name}}')
            ->assertJsonPath('data.isActive', false)
            ->assertJsonPath('data.content', $this->content())
            ->assertJsonPath('data.body', self::DERIVED_BODY);
    }

    public function test_a_designed_template_can_become_plain_text_again(): void
    {
        $this->actingAsAdmin();

        $keeps = $this->designedTemplate('Follow-up', 'Casual');
        $this->patchTemplate($keeps, ['content' => null])
            ->assertOk()
            ->assertJsonPath('data.isDesigned', false)
            ->assertJsonPath('data.content', null)
            ->assertJsonPath('data.emailDesignId', null)
            ->assertJsonPath('data.body', self::DERIVED_BODY);

        $rewrites = $this->designedTemplate('Follow-up', 'Formal');
        $this->patchTemplate($rewrites, ['content' => null, 'body' => 'Plain again, {{first_name}}'])
            ->assertOk()
            ->assertJsonPath('data.body', 'Plain again, {{first_name}}');
    }

    public function test_invalid_content_leaves_the_template_unchanged(): void
    {
        $this->actingAsAdmin();
        $template = $this->designedTemplate();

        $this->patchTemplate($template, ['content' => $this->contentJson($this->content([$this->image(['asset_id' => 'https://evil.example/a.png'])]))])
            ->assertJsonValidationErrors(['content.blocks.0.asset_id' => EmailDesignValidator::INVALID_ASSET]);

        $this->assertSame($this->content(), $template->fresh()->content);
        $this->assertSame(self::DERIVED_BODY, $template->fresh()->body);
    }

    public function test_choosing_another_design_alone_rederives_the_body(): void
    {
        $this->actingAsAdmin();
        $template = $this->designedTemplate();
        $campaign = $this->campaignDesign();

        $this->patchTemplate($template, ['email_design_id' => $campaign->id])
            ->assertOk()
            ->assertJsonPath('data.emailDesignId', (string) $campaign->id)
            ->assertJsonPath('data.content', $this->content())
            ->assertJsonPath('data.body', str_replace('Best regards,', 'Warm wishes,', self::DERIVED_BODY));
    }

    public function test_a_design_is_refused_for_a_plain_template(): void
    {
        $this->actingAsAdmin();
        $template = $this->template('Follow-up', 'Casual');

        $this->patchTemplate($template, ['email_design_id' => $this->defaultDesign()->id])
            ->assertJsonValidationErrors(['email_design_id' => EmailTemplateManagementService::DESIGN_ONLY_FOR_DESIGNED]);

        $this->assertNull($template->fresh()->email_design_id);
    }

    public function test_an_automation_templates_protection_still_applies_once_designed(): void
    {
        $this->actingAsAdmin();
        $template = $this->designedTemplate('proposal', 'Warm & professional');

        $this->patchTemplate($template, ['is_active' => false])->assertJsonValidationErrors('is_active');
        $this->patchTemplate($template, ['purpose' => 'thank_you'])->assertJsonValidationErrors('purpose');
        $this->deleteJson(self::URL."/{$template->id}")->assertJsonValidationErrors('template');
    }

    // ── Readers of `body` ─────────────────────────────────────────────────────────────────────

    public function test_a_designed_template_reaches_the_composer_as_its_rendered_plain_text(): void
    {
        $this->actingAsAdmin();
        $template = $this->designedTemplate('Follow-up', 'Casual');

        $bd = $this->user('crm_bd', ['name' => 'Alex Reyes']);
        $lead = Lead::factory()->create([
            'assigned_to' => $bd->id,
            'created_by' => $bd->id,
            'cold_calling' => 'not-called',
            'contact_name' => 'Maria Santos',
            'business_name' => 'Santos Bakery',
        ]);
        Sanctum::actingAs($bd);

        $row = collect($this->getJson("/api/v1/crm/leads/{$lead->id}/email-templates")->assertOk()->json('data'))
            ->firstWhere('id', (string) $template->id);

        // Same shape as every other template; the body is the derived text with the lead's values filled in.
        $this->assertSame(['id', 'purpose', 'tone', 'subject', 'body'], array_keys($row));
        $this->assertSame(
            str_replace(['{{first_name}}', '{{business_name}}', '{{sender_name}}'], ['Maria', 'Santos Bakery', 'Alex Reyes'], self::DERIVED_BODY),
            $row['body'],
        );
    }

    // ── Preview ───────────────────────────────────────────────────────────────────────────────

    public function test_the_preview_renders_the_default_design_through_the_same_renderer(): void
    {
        $this->actingAsAdmin();
        $service = app(EmailTemplateDesignService::class);
        $renderer = app(EmailDesignRenderer::class);
        $layout = $this->defaultDesign()->layout;

        $response = $this->preview(['content' => $this->contentJson(), 'subject' => 'Your proposal, {{first_name}}'])->assertOk();

        $this->assertSame($renderer->toHtml($layout, $this->content(), $service->sampleContext()), $response->json('data.html'));
        $this->assertSame($renderer->toText($layout, $this->content(), $service->sampleContext()), $response->json('data.text'));
        $this->assertSame('Your proposal, Maria', $response->json('data.subject'));
        $this->assertSame($service->htmlBytes($layout, $this->content()), $response->json('data.htmlBytes'));
        $this->assertSame(EmailTemplateDesignService::MAX_HTML_BYTES, $response->json('data.maxHtmlBytes'));

        $html = $response->json('data.html');
        $this->assertStringStartsWith('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('>Hi Maria,</h1>', $html);
        $this->assertStringContainsString('<strong>Santos Bakery</strong>', $html);
        $this->assertStringContainsString('<strong>Alex Reyes</strong>', $html);
        $this->assertStringNotContainsString('{{', $html);
    }

    public function test_the_preview_renders_the_chosen_design(): void
    {
        $this->actingAsAdmin();
        $campaign = $this->campaignDesign([$this->footer(['content' => $this->doc($this->para($this->text('Campaign footer')))])]);

        $html = $this->preview(['content' => $this->contentJson(), 'email_design_id' => $campaign->id])->assertOk()->json('data.html');

        $this->assertStringContainsString('Campaign footer', $html);
        $this->assertStringContainsString('Warm wishes,', $html);
        $this->assertStringNotContainsString('Best regards,', $html);
    }

    public function test_the_preview_samples_are_the_catalogs(): void
    {
        $this->assertSame(
            array_map(fn (array $token): string => $token['sample'], EmailTemplateCatalog::TOKENS),
            app(EmailTemplateDesignService::class)->sampleContext()->tokens,
        );
    }

    public function test_the_preview_subject_is_optional(): void
    {
        $this->actingAsAdmin();

        $this->preview(['content' => $this->contentJson()])->assertOk()->assertJsonPath('data.subject', '');
    }

    public function test_the_preview_writes_no_template(): void
    {
        $this->actingAsAdmin();
        $this->defaultDesign();
        $snapshot = fn (): array => EmailTemplate::withTrashed()->orderBy('id')->get(['id', 'subject', 'body', 'content', 'email_design_id', 'updated_at'])->toArray();
        $before = $snapshot();

        $this->preview(['content' => $this->contentJson(), 'subject' => 'Hello'])->assertOk();

        $this->assertSame($before, $snapshot());
        $this->assertSame(1, EmailDesign::query()->count());
    }

    public function test_the_preview_refuses_what_saving_refuses(): void
    {
        $this->actingAsAdmin();

        $this->preview([])->assertJsonValidationErrors('content');
        $this->preview(['content' => $this->content()])->assertJsonValidationErrors(['content' => 'Send the template content as a JSON-encoded string.']);
        $this->preview(['content' => $this->contentJson($this->content([$this->button(['url' => 'http://example.com'])]))])
            ->assertJsonValidationErrors(['content.blocks.0.url' => EmailDesignValidator::UNSAFE_URL]);
        $this->preview(['content' => $this->contentJson($this->gmailSizedContent())])
            ->assertJsonValidationErrors(['content' => EmailTemplateDesignService::HTML_TOO_LARGE]);
        $this->preview(['content' => $this->contentJson(), 'subject' => 'Hi {{phone}}'])->assertJsonValidationErrors('subject');
        $this->preview(['content' => $this->contentJson(), 'email_design_id' => 999999])->assertJsonValidationErrors('email_design_id');
    }

    public function test_the_preview_omits_images_and_the_logo_until_assets_and_branding_exist(): void
    {
        $this->actingAsAdmin();

        $html = $this->preview(['content' => $this->contentJson($this->content([$this->heading(), $this->image()]))])->assertOk()->json('data.html');

        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_the_preview_renders_inherited_styles_overrides_and_the_preview_text(): void
    {
        $this->actingAsAdmin();

        $overridden = $this->preview(['content' => $this->contentJson($this->content(
            [$this->uncolored($this->heading()), $this->uncolored($this->button())],
            ['preheader' => 'Ideas for {{business_name}}', 'style_overrides' => ['accent_color' => '#AA0000']],
        ))])->assertOk()->json('data.html');

        $this->assertStringContainsString('<td align="center" bgcolor="#aa0000"', $overridden);
        $this->assertStringContainsString('font-weight:bold;color:'.EmailDesignSchema::SYSTEM_DEFAULTS['text_color'].';', $overridden);
        $this->assertStringContainsString('mso-hide:all;">Ideas for Santos Bakery&#847;', $overridden);

        $inheriting = $this->preview(['content' => $this->contentJson($this->content([$this->uncolored($this->button())]))])->assertOk()->json('data.html');
        $this->assertStringContainsString('<td align="center" bgcolor="'.EmailDesignSchema::SYSTEM_DEFAULTS['accent_color'].'"', $inheriting);
    }

    // ── RBAC & tenancy ────────────────────────────────────────────────────────────────────────

    public function test_only_management_roles_can_preview_or_save_content(): void
    {
        $template = $this->template('Follow-up', 'Casual');

        Sanctum::actingAs($this->user('crm_bd'));
        $this->preview(['content' => $this->contentJson()])->assertForbidden();
        $this->postJson(self::URL, $this->payload())->assertForbidden();
        $this->patchTemplate($template, ['content' => $this->contentJson()])->assertForbidden();
        $this->assertNull($template->fresh()->content);

        Sanctum::actingAs($this->user('super_admin'));
        $this->preview(['content' => $this->contentJson()])->assertOk();
    }

    public function test_a_guest_cannot_preview(): void
    {
        $this->preview(['content' => $this->contentJson()])->assertUnauthorized();
    }

    public function test_another_tenants_template_cannot_be_given_content(): void
    {
        [$theirOrg] = $this->orgWithAdmin('theirs');
        [, $ourAdmin] = $this->orgWithAdmin('ours');

        $theirs = CurrentOrg::runAs($theirOrg->id, fn (): EmailTemplate => EmailTemplate::query()->where('purpose', 'Follow-up')->firstOrFail());

        CurrentOrg::clear();
        Sanctum::actingAs($ourAdmin);

        $this->patchTemplate($theirs, ['content' => $this->contentJson()])->assertNotFound();

        $this->assertNull(EmailTemplate::withoutGlobalScopes()->find($theirs->id)->content);
    }

    public function test_another_tenants_design_cannot_be_used(): void
    {
        [$theirOrg] = $this->orgWithAdmin('theirs');
        [, $ourAdmin] = $this->orgWithAdmin('ours');

        $theirDesign = CurrentOrg::runAs($theirOrg->id, fn (): EmailDesign => EmailDesign::create([
            'name' => 'Theirs', 'is_default' => false, 'layout' => $this->layout(),
        ]));

        CurrentOrg::clear();
        Sanctum::actingAs($ourAdmin);

        $this->postJson(self::URL, $this->payload(['email_design_id' => $theirDesign->id]))
            ->assertJsonValidationErrors(['email_design_id' => EmailTemplateManagementService::UNKNOWN_DESIGN]);
        $this->preview(['content' => $this->contentJson(), 'email_design_id' => $theirDesign->id])
            ->assertJsonValidationErrors(['email_design_id' => EmailTemplateManagementService::UNKNOWN_DESIGN]);
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
