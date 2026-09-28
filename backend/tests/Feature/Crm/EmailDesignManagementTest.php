<?php

namespace Tests\Feature\Crm;

use App\Models\Org;
use App\Models\User;
use App\Modules\Crm\Database\Seeders\EmailTemplateSeeder;
use App\Modules\Crm\Models\EmailDesign;
use App\Modules\Crm\Models\EmailTemplate;
use App\Modules\Crm\Services\Outreach\EmailDesignService;
use App\Modules\Crm\Services\Outreach\EmailTemplateDesignService;
use App\Modules\Crm\Support\EmailDesign\EmailDesignRenderer;
use App\Modules\Crm\Support\EmailDesign\EmailDesignValidator;
use App\Services\OrgOnboardingService;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Tests\Unit\Crm\EmailDesign\BuildsEmailDesigns;

/**
 * Settings → Email Designs: the reusable looks designed templates share.
 *
 * The reuse cases carry the most weight. One design is saved once, any number of templates use it, and a
 * change to it reaches every one of them — while templates in other designs, and plain-text templates, are
 * left exactly as they were. The default design is created per organization, once, and never overwritten.
 */
class EmailDesignManagementTest extends TestCase
{
    use BuildsEmailDesigns;
    use RefreshDatabase;

    private const URL = '/api/v1/crm/settings/email-designs';

    private const TEMPLATES_URL = '/api/v1/crm/settings/email-templates';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(EmailTemplateSeeder::class);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['status' => 'active']);
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

    /** @param  array<string, mixed>|null  $layout */
    private function layoutJson(?array $layout = null): string
    {
        return (string) json_encode($layout ?? $this->layout());
    }

    /** @param  array<string, mixed>|null  $content */
    private function contentJson(?array $content = null): string
    {
        return (string) json_encode($content ?? $this->content());
    }

    private function defaultDesign(): EmailDesign
    {
        return app(EmailDesignService::class)->defaultDesign();
    }

    /** @param  array<string, mixed>|null  $layout */
    private function createDesign(string $name, ?array $layout = null): EmailDesign
    {
        $id = $this->postJson(self::URL, ['name' => $name, 'layout' => $this->layoutJson($layout)])->assertCreated()->json('data.id');

        return EmailDesign::query()->findOrFail($id);
    }

    /** @param  array<string, mixed>|null  $content */
    private function useDesign(EmailTemplate $template, ?EmailDesign $design, ?array $content = null): EmailTemplate
    {
        $body = ['content' => $this->contentJson($content)];
        if ($design !== null) {
            $body['email_design_id'] = $design->id;
        }
        $this->patchJson(self::TEMPLATES_URL."/{$template->id}", $body)->assertOk();

        return $template->fresh();
    }

    /** @return array<string, mixed> */
    private function footerSaying(string $text): array
    {
        return $this->footer(['content' => $this->doc($this->para($this->text($text)))]);
    }

    // ── The default design ────────────────────────────────────────────────────────────────────

    public function test_listing_designs_creates_the_organizations_default_design_once(): void
    {
        $this->actingAsAdmin();
        $this->assertSame(0, EmailDesign::query()->count());

        $response = $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.isDefault', true)
            ->assertJsonPath('data.0.templatesCount', 0);

        $org = Org::query()->findOrFail(CurrentOrg::id());
        $this->assertSame($org->name.' Default', $response->json('data.0.name'));
        $this->assertEquals(EmailDesignService::defaultLayout(), $response->json('data.0.layout'));

        $this->getJson(self::URL)->assertOk()->assertJsonCount(1, 'data');
        $this->defaultDesign();
        $this->assertSame(1, EmailDesign::query()->where('is_default', true)->count());
    }

    public function test_the_default_layout_is_valid(): void
    {
        $this->assertNull((new EmailDesignValidator)->layoutProblem(EmailDesignService::defaultLayout()));
    }

    public function test_the_default_design_is_never_overwritten(): void
    {
        $this->actingAsAdmin();
        $default = $this->defaultDesign();
        $custom = $this->layout([], [$this->footerSaying('Our own footer')], signature: ['sign_off' => 'Warm wishes,']);

        $this->patchJson(self::URL."/{$default->id}", ['name' => 'Vite SEO Brand', 'layout' => $this->layoutJson($custom)])->assertOk();

        $this->getJson(self::URL)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Vite SEO Brand');
        $this->assertSame($default->id, $this->defaultDesign()->id);
        $this->assertEquals($custom, $default->fresh()->layout);
        $this->assertSame(1, EmailDesign::query()->count());
    }

    public function test_each_organization_gets_its_own_default_design(): void
    {
        [, $theirAdmin] = $this->orgWithAdmin('theirs');
        [, $ourAdmin] = $this->orgWithAdmin('ours');
        CurrentOrg::clear();

        Sanctum::actingAs($theirAdmin);
        $this->getJson(self::URL)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Org theirs Default');

        Sanctum::actingAs($ourAdmin);
        $this->getJson(self::URL)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Org ours Default');

        $this->assertSame(2, EmailDesign::withoutGlobalScopes()->where('is_default', true)->count());
    }

    public function test_a_new_designed_template_uses_the_default_design(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson(self::TEMPLATES_URL, [
            'purpose' => 'proposal', 'tone' => 'Formal', 'subject' => 'Your proposal', 'content' => $this->contentJson(),
        ])->assertCreated();

        $default = $this->defaultDesign();
        $response->assertJsonPath('data.emailDesignId', (string) $default->id)->assertJsonPath('data.emailDesignName', $default->name);
    }

    // ── Creating and editing designs ──────────────────────────────────────────────────────────

    public function test_an_admin_can_create_a_design(): void
    {
        $admin = $this->actingAsAdmin();

        $this->postJson(self::URL, ['name' => 'Campaign', 'layout' => $this->layoutJson()])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Campaign')
            ->assertJsonPath('data.isDefault', false)
            ->assertJsonPath('data.templatesCount', 0)
            ->assertJsonPath('data.layout', $this->layout());

        $this->assertSame($admin->id, EmailDesign::query()->where('name', 'Campaign')->firstOrFail()->created_by);
    }

    public function test_a_design_needs_a_unique_name(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::URL, ['layout' => $this->layoutJson()])->assertJsonValidationErrors('name');
        $this->postJson(self::URL, ['name' => '   ', 'layout' => $this->layoutJson()])->assertJsonValidationErrors('name');
        $this->postJson(self::URL, ['name' => $this->defaultDesign()->name, 'layout' => $this->layoutJson()])
            ->assertJsonValidationErrors(['name' => EmailDesignService::NAME_TAKEN]);
    }

    public function test_the_layout_is_validated_at_its_path_and_nothing_is_saved(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::URL, ['name' => 'Bad', 'layout' => $this->layout()])
            ->assertJsonValidationErrors(['layout' => 'Send the design layout as a JSON-encoded string.']);
        $this->postJson(self::URL, ['name' => 'Bad', 'layout' => $this->layoutJson($this->layout([$this->logo(['link_url' => 'javascript:alert(1)'])], []))])
            ->assertJsonValidationErrors(['layout.header.0.link_url' => EmailDesignValidator::UNSAFE_URL]);
        $this->postJson(self::URL, ['name' => 'Bad', 'layout' => $this->layoutJson($this->layout([$this->button()], []))])
            ->assertJsonValidationErrors(['layout.header.0.type' => EmailDesignValidator::BLOCK_NOT_ALLOWED]);
        $this->postJson(self::URL, ['name' => 'Bad', 'layout' => str_repeat('[', 40).str_repeat(']', 40)])
            ->assertJsonValidationErrors(['layout' => EmailDesignValidator::TOO_DEEP]);

        $this->assertSame(0, EmailDesign::query()->count());
    }

    public function test_an_admin_can_rename_a_design_but_not_onto_another_name(): void
    {
        $this->actingAsAdmin();
        $campaign = $this->createDesign('Campaign');
        $this->createDesign('Newsletter');

        $this->patchJson(self::URL."/{$campaign->id}", ['name' => 'Spring campaign'])->assertOk()->assertJsonPath('data.name', 'Spring campaign');
        $this->patchJson(self::URL."/{$campaign->id}", ['name' => 'Newsletter'])->assertJsonValidationErrors(['name' => EmailDesignService::NAME_TAKEN]);

        $this->assertSame('Spring campaign', $campaign->fresh()->name);
    }

    // ── Reuse ─────────────────────────────────────────────────────────────────────────────────

    public function test_several_templates_can_use_the_same_design(): void
    {
        $this->actingAsAdmin();
        $campaign = $this->createDesign('Campaign');

        $this->useDesign($this->template('Follow-up', 'Casual'), $campaign);
        $this->useDesign($this->template('Introduction', 'Casual'), $campaign);

        $designs = collect($this->getJson(self::URL)->assertOk()->json('data'))->keyBy('name');
        $this->assertSame(2, $designs['Campaign']['templatesCount']);
        $this->assertSame(0, $designs[$this->defaultDesign()->name]['templatesCount']);

        $rows = collect($this->getJson(self::TEMPLATES_URL)->assertOk()->json('data'))->where('emailDesignName', 'Campaign');
        $this->assertCount(2, $rows);
        $this->assertSame([(string) $campaign->id], $rows->pluck('emailDesignId')->unique()->values()->all());
    }

    public function test_changing_a_design_changes_every_template_that_uses_it_and_nothing_else(): void
    {
        $this->actingAsAdmin();
        $campaign = $this->createDesign('Campaign', $this->layout([], []));

        $first = $this->useDesign($this->template('Follow-up', 'Casual'), $campaign);
        $second = $this->useDesign($this->template('Introduction', 'Casual'), $campaign);
        $elsewhere = $this->useDesign($this->template('Follow-up', 'Formal'), null);
        $plain = $this->template('Proposal Check-in', 'Casual');
        $plainBody = $plain->body;

        $this->travel(5)->minutes();

        $this->patchJson(self::URL."/{$campaign->id}", [
            'layout' => $this->layoutJson($this->layout([], [$this->footerSaying('Campaign footer')], signature: ['sign_off' => 'Warm wishes,'])),
        ])->assertOk();

        foreach ([$first, $second] as $template) {
            $fresh = $template->fresh();
            $this->assertStringContainsString("Warm wishes,\n{{sender_name}}", $fresh->body);
            $this->assertStringEndsWith('Campaign footer', $fresh->body);
            $this->assertStringNotContainsString('Best regards,', $fresh->body);
            $this->assertSame($template->content, $fresh->content, 'the template content is untouched');
            $this->assertTrue($template->updated_at->equalTo($fresh->updated_at), 'the template itself was not edited');
        }

        $this->assertStringContainsString('Best regards,', $elsewhere->fresh()->body);
        $this->assertStringNotContainsString('Campaign footer', $elsewhere->fresh()->body);
        $this->assertSame($plainBody, $plain->fresh()->body);
        $this->assertNull($plain->fresh()->content);

        $html = $this->postJson(self::TEMPLATES_URL.'/preview', ['content' => $this->contentJson($first->content), 'email_design_id' => $campaign->id])
            ->assertOk()
            ->json('data.html');
        $this->assertStringContainsString('Campaign footer', $html);
        $this->assertStringContainsString('Warm wishes,', $html);
    }

    public function test_a_design_change_that_would_break_a_template_is_refused(): void
    {
        $this->actingAsAdmin();
        $campaign = $this->createDesign('Campaign', $this->layout([], []));
        $long = $this->content([
            $this->textBlock(['id' => 'one', 'content' => $this->doc($this->para($this->text(str_repeat('a', 4500))))]),
            $this->textBlock(['id' => 'two', 'content' => $this->doc($this->para($this->text(str_repeat('a', 4500))))]),
        ]);
        $template = $this->useDesign($this->template('Follow-up', 'Casual'), $campaign, $long);
        $layout = $campaign->fresh()->layout;

        $errors = $this->patchJson(self::URL."/{$campaign->id}", [
            'layout' => $this->layoutJson($this->layout([], [$this->footerSaying(str_repeat('b', 1500))])),
        ])->assertJsonValidationErrors('layout')->json('errors.layout');

        $this->assertStringContainsString(EmailTemplateDesignService::TEXT_TOO_LONG, $errors[0]);
        $this->assertStringContainsString($template->subject, $errors[0]);
        $this->assertEquals($layout, $campaign->fresh()->layout);
        $this->assertSame($template->body, $template->fresh()->body);
    }

    public function test_moving_a_template_to_another_design_rederives_its_body(): void
    {
        $this->actingAsAdmin();
        $template = $this->useDesign($this->template('Follow-up', 'Casual'), null);
        $campaign = $this->createDesign('Campaign', $this->layout([], [], signature: ['sign_off' => 'Warm wishes,']));

        $this->patchJson(self::TEMPLATES_URL."/{$template->id}", ['email_design_id' => $campaign->id])->assertOk();

        $this->assertSame($campaign->id, $template->fresh()->email_design_id);
        $this->assertStringEndsWith("Warm wishes,\n{{sender_name}}", $template->fresh()->body);
    }

    // ── Deleting ──────────────────────────────────────────────────────────────────────────────

    public function test_the_default_design_cannot_be_deleted(): void
    {
        $this->actingAsAdmin();
        $default = $this->defaultDesign();

        $this->deleteJson(self::URL."/{$default->id}")->assertJsonValidationErrors(['design' => EmailDesignService::DEFAULT_CANNOT_BE_DELETED]);

        $this->assertNotSoftDeleted($default);
    }

    public function test_a_design_in_use_cannot_be_deleted(): void
    {
        $this->actingAsAdmin();
        $campaign = $this->createDesign('Campaign');
        $this->useDesign($this->template('Follow-up', 'Casual'), $campaign);

        $this->deleteJson(self::URL."/{$campaign->id}")
            ->assertJsonValidationErrors(['design' => sprintf(EmailDesignService::IN_USE, 1, 'template', 'it')]);

        $this->assertNotSoftDeleted($campaign);
    }

    public function test_an_unused_design_can_be_deleted(): void
    {
        $this->actingAsAdmin();
        $campaign = $this->createDesign('Campaign');

        $this->deleteJson(self::URL."/{$campaign->id}")->assertOk();

        $this->assertSoftDeleted($campaign);
        $this->assertNotContains('Campaign', array_column($this->getJson(self::URL)->assertOk()->json('data'), 'name'));
    }

    public function test_a_design_whose_templates_were_deleted_can_be_deleted(): void
    {
        $this->actingAsAdmin();
        $campaign = $this->createDesign('Campaign');
        $template = $this->useDesign($this->template('Follow-up', 'Casual'), $campaign);

        $this->deleteJson(self::TEMPLATES_URL."/{$template->id}")->assertOk();
        $this->deleteJson(self::URL."/{$campaign->id}")->assertOk();

        $this->assertSoftDeleted($campaign);
    }

    // ── Preview ───────────────────────────────────────────────────────────────────────────────

    public function test_the_design_preview_renders_the_layout_with_sample_content(): void
    {
        $this->actingAsAdmin();
        $service = app(EmailTemplateDesignService::class);
        $layout = $this->layout([], [$this->footerSaying('Design footer')], signature: ['sign_off' => 'Warm wishes,']);

        $response = $this->postJson(self::URL.'/preview', ['layout' => $this->layoutJson($layout)])->assertOk();

        $this->assertSame(
            app(EmailDesignRenderer::class)->toHtml($layout, $service->sampleContent(), $service->sampleContext()),
            $response->json('data.html'),
        );
        $this->assertSame(EmailTemplateDesignService::PREVIEW_SUBJECT, $response->json('data.subject'));
        $this->assertStringContainsString('Hi Maria,', $response->json('data.html'));
        $this->assertStringContainsString('Design footer', $response->json('data.html'));
        $this->assertStringContainsString('Warm wishes,', $response->json('data.html'));
        $this->assertSame(0, EmailDesign::query()->count(), 'a design preview writes nothing');
    }

    public function test_the_design_preview_refuses_an_invalid_layout(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::URL.'/preview', [])->assertJsonValidationErrors('layout');
        $this->postJson(self::URL.'/preview', ['layout' => $this->layoutJson($this->layout([$this->button()], []))])
            ->assertJsonValidationErrors(['layout.header.0.type' => EmailDesignValidator::BLOCK_NOT_ALLOWED]);
    }

    // ── RBAC & tenancy ────────────────────────────────────────────────────────────────────────

    public function test_a_business_developer_is_refused_every_design_route(): void
    {
        $design = EmailDesign::create(['name' => 'Campaign', 'is_default' => false, 'layout' => $this->layout()]);
        Sanctum::actingAs($this->user('crm_bd'));

        $this->getJson(self::URL)->assertForbidden();
        $this->postJson(self::URL, ['name' => 'Mine', 'layout' => $this->layoutJson()])->assertForbidden();
        $this->postJson(self::URL.'/preview', ['layout' => $this->layoutJson()])->assertForbidden();
        $this->patchJson(self::URL."/{$design->id}", ['name' => 'Changed'])->assertForbidden();
        $this->deleteJson(self::URL."/{$design->id}")->assertForbidden();

        $this->assertSame('Campaign', $design->fresh()->name);
        $this->assertNotSoftDeleted($design);
    }

    public function test_a_guest_is_refused(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function test_another_tenants_design_is_not_found(): void
    {
        [$theirOrg] = $this->orgWithAdmin('theirs');
        [, $ourAdmin] = $this->orgWithAdmin('ours');

        $theirs = CurrentOrg::runAs($theirOrg->id, fn (): EmailDesign => EmailDesign::create([
            'name' => 'Theirs', 'is_default' => false, 'layout' => $this->layout(),
        ]));

        CurrentOrg::clear();
        Sanctum::actingAs($ourAdmin);

        $this->patchJson(self::URL."/{$theirs->id}", ['name' => 'Hijacked'])->assertNotFound();
        $this->deleteJson(self::URL."/{$theirs->id}")->assertNotFound();
        $this->assertNotContains('Theirs', array_column($this->getJson(self::URL)->assertOk()->json('data'), 'name'));

        $this->assertSame('Theirs', EmailDesign::withoutGlobalScopes()->find($theirs->id)->name);
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
