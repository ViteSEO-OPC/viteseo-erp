<?php

namespace Tests\Feature\Crm;

use App\Models\Org;
use App\Models\User;
use App\Modules\Crm\Controllers\AutomationEmailController;
use App\Modules\Crm\Controllers\EmailTemplateController;
use App\Modules\Crm\Database\Seeders\EmailTemplateSeeder;
use App\Modules\Crm\Models\EmailTemplate;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Outreach\TemplateRenderer;
use App\Modules\Crm\Support\EmailTemplateCatalog;
use App\Services\OrgOnboardingService;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClassConstant;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Settings → Email Templates — the management CRUD and the rules that protect what reads templates.
 *
 * The automation cases carry the most weight. The n8n endpoint answers 422 for a purpose with no active
 * template, so an admin must never be able to leave one of its purposes empty by deactivating or
 * deleting the last template for it. Those cases drive the REAL private resolver by reflection, as
 * ComposerTemplatePurposesTest does, so they cannot pass against a restated query.
 */
class EmailTemplateManagementTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/crm/settings/email-templates';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        // The real catalog — composer and automation templates, exactly as production holds them.
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

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return [
            'purpose' => 'proposal',
            'tone' => 'Formal',
            'subject' => 'Your proposal, {{first_name}}',
            'body' => "Dear {{contact_name}},\n\nThe proposal for {{business_name}} is attached.\n\n{{sender_name}}",
            ...$overrides,
        ];
    }

    private function patchTemplate(EmailTemplate $template, array $body): TestResponse
    {
        return $this->patchJson(self::URL."/{$template->id}", $body);
    }

    private function resolveAutomation(string $purpose, string $tone = 'Warm & professional'): EmailTemplate
    {
        $resolve = new ReflectionMethod(AutomationEmailController::class, 'resolveTemplate');

        return $resolve->invoke(new AutomationEmailController, $purpose, $tone);
    }

    // ── RBAC ──────────────────────────────────────────────────────────────────────────────────

    public function test_a_super_admin_can_list_templates(): void
    {
        Sanctum::actingAs($this->user('super_admin'));

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(EmailTemplate::query()->count(), 'data')
            ->assertJsonPath('meta.tones', EmailTemplateCatalog::TONES)
            ->assertJsonStructure(['meta' => ['purposes' => [['value', 'kind']], 'tokens' => [['key', 'label', 'sample']], 'automationTemplateIds']]);
    }

    public function test_an_admin_can_list_templates(): void
    {
        $this->actingAsAdmin();

        $this->getJson(self::URL)->assertOk();
    }

    public function test_a_business_developer_is_refused_every_route(): void
    {
        Sanctum::actingAs($this->user('crm_bd'));
        $template = $this->template('Follow-up');

        $this->getJson(self::URL)->assertForbidden();
        $this->postJson(self::URL, $this->payload())->assertForbidden();
        $this->patchTemplate($template, ['subject' => 'Changed'])->assertForbidden();
        $this->deleteJson(self::URL."/{$template->id}")->assertForbidden();

        $this->assertNotSame('Changed', $template->fresh()->subject);
        $this->assertNotSoftDeleted($template);
    }

    public function test_a_guest_is_refused(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    // ── Listing ───────────────────────────────────────────────────────────────────────────────

    public function test_the_list_shows_inactive_templates_with_their_raw_placeholders(): void
    {
        $this->actingAsAdmin();
        $template = $this->template('Follow-up', 'Casual');
        $template->update(['is_active' => false]);

        $row = collect($this->getJson(self::URL)->assertOk()->json('data'))->firstWhere('id', (string) $template->id);

        $this->assertFalse($row['isActive']);
        $this->assertStringContainsString('{{first_name}}', $row['body'], 'the editor must receive the authored text, not a rendering');
        $this->assertSame('composer', $row['kind']);
    }

    public function test_automation_templates_are_identified(): void
    {
        $this->actingAsAdmin();
        $proposal = $this->template('proposal');

        $response = $this->getJson(self::URL)->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', (string) $proposal->id);

        $this->assertSame('automation', $row['kind']);
        $this->assertContains((string) $proposal->id, $response->json('meta.automationTemplateIds'));
    }

    // ── Creating ──────────────────────────────────────────────────────────────────────────────

    public function test_an_admin_can_create_a_template(): void
    {
        $admin = $this->actingAsAdmin();

        $this->postJson(self::URL, $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.kind', 'automation')
            ->assertJsonPath('data.isActive', true);

        $this->assertSame($admin->id, $this->template('proposal', 'Formal')->created_by);
    }

    /** @return array<string, array{0: string}> */
    public static function purposesOutsideTheCatalog(): array
    {
        return [
            'free-form' => ['Cold pitch'],
            'case variant of a composer purpose' => ['follow-up'],
            'case variant of an automation key' => ['Thank_you'],
        ];
    }

    #[DataProvider('purposesOutsideTheCatalog')]
    public function test_a_purpose_outside_the_catalog_is_refused(string $purpose): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::URL, $this->payload(['purpose' => $purpose]))->assertJsonValidationErrors('purpose');
    }

    public function test_a_tone_outside_the_catalog_is_refused(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::URL, $this->payload(['tone' => 'Aggressive']))->assertJsonValidationErrors('tone');
    }

    /** @return array<string, array{0: string}> */
    public static function badPlaceholders(): array
    {
        return [
            'not in the whitelist' => ['Call me at {{phone}}'],
            'wrong case, sent verbatim' => ['Hi {{FirstName}}'],
            'space inside, sent verbatim' => ['Hi {{ first name }}'],
            'missing its closing braces' => ['Hi {{first_name}'],
            'missing its opening braces' => ['Hi first_name}}'],
        ];
    }

    #[DataProvider('badPlaceholders')]
    public function test_a_placeholder_the_renderer_cannot_substitute_is_refused(string $body): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::URL, $this->payload(['body' => $body]))->assertJsonValidationErrors('body');
        $this->postJson(self::URL, $this->payload(['subject' => $body]))->assertJsonValidationErrors('subject');
    }

    public function test_every_catalog_placeholder_is_accepted(): void
    {
        $this->actingAsAdmin();
        $body = implode(' ', array_map(fn (string $key): string => '{{ '.$key.' }}', array_keys(EmailTemplateCatalog::TOKENS)));

        $this->postJson(self::URL, $this->payload(['body' => $body]))->assertCreated();
    }

    public function test_a_second_template_for_the_same_purpose_and_tone_is_refused(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::URL, $this->payload(['purpose' => 'Follow-up', 'tone' => 'Casual']))
            ->assertJsonValidationErrors('tone');
    }

    public function test_a_deleted_templates_purpose_and_tone_can_be_used_again(): void
    {
        $this->actingAsAdmin();
        $template = $this->template('Follow-up', 'Casual');

        $this->deleteJson(self::URL."/{$template->id}")->assertOk();
        $this->assertSoftDeleted($template);

        $this->postJson(self::URL, $this->payload(['purpose' => 'Follow-up', 'tone' => 'Casual']))->assertCreated();
    }

    // ── Editing ───────────────────────────────────────────────────────────────────────────────

    public function test_an_edit_reaches_the_outreach_composer_unchanged_in_shape(): void
    {
        $this->actingAsAdmin();
        $template = $this->template('Follow-up', 'Casual');

        $this->patchTemplate($template, ['subject' => 'Checking in with {{business_name}}'])
            ->assertOk()
            ->assertJsonPath('data.subject', 'Checking in with {{business_name}}');

        $bd = $this->user('crm_bd');
        $lead = Lead::factory()->create([
            'assigned_to' => $bd->id,
            'created_by' => $bd->id,
            'cold_calling' => 'not-called',
            'business_name' => 'Santos Bakery',
        ]);
        Sanctum::actingAs($bd);

        $row = collect($this->getJson("/api/v1/crm/leads/{$lead->id}/email-templates")->assertOk()->json('data'))
            ->firstWhere('id', (string) $template->id);

        $this->assertSame('Checking in with Santos Bakery', $row['subject']);
    }

    public function test_a_deactivated_template_leaves_the_composer(): void
    {
        $this->actingAsAdmin();
        $template = $this->template('Introduction', 'Casual');

        $this->patchTemplate($template, ['is_active' => false])->assertOk()->assertJsonPath('data.isActive', false);

        $bd = $this->user('crm_bd');
        $lead = Lead::factory()->create(['assigned_to' => $bd->id, 'created_by' => $bd->id, 'cold_calling' => 'not-called']);
        Sanctum::actingAs($bd);

        $ids = array_column($this->getJson("/api/v1/crm/leads/{$lead->id}/email-templates")->assertOk()->json('data'), 'id');

        $this->assertNotContains((string) $template->id, $ids);
    }

    public function test_a_composer_template_cannot_become_an_automation_template(): void
    {
        $this->actingAsAdmin();

        $this->patchTemplate($this->template('Follow-up', 'Casual'), ['purpose' => 'thank_you'])->assertJsonValidationErrors('purpose');
    }

    public function test_a_composer_template_can_move_to_a_free_composer_purpose(): void
    {
        $this->actingAsAdmin();
        $this->deleteJson(self::URL.'/'.$this->template('Introduction', 'Casual')->id)->assertOk();
        $template = $this->template('Follow-up', 'Casual');

        $this->patchTemplate($template, ['purpose' => 'Introduction'])->assertOk()->assertJsonPath('data.purpose', 'Introduction');
    }

    public function test_moving_onto_a_taken_purpose_and_tone_is_refused(): void
    {
        $this->actingAsAdmin();

        $this->patchTemplate($this->template('Follow-up', 'Casual'), ['tone' => 'Formal'])->assertJsonValidationErrors('tone');
    }

    // ── Automation protection ─────────────────────────────────────────────────────────────────

    public function test_the_only_active_automation_template_cannot_be_deactivated(): void
    {
        $this->actingAsAdmin();
        $template = $this->template('thank_you');

        $this->patchTemplate($template, ['is_active' => false])->assertJsonValidationErrors('is_active');

        $this->assertTrue($template->fresh()->is_active);
        $this->assertSame($template->id, $this->resolveAutomation('thank_you')->id);
    }

    public function test_the_only_active_automation_template_cannot_be_deleted(): void
    {
        $this->actingAsAdmin();
        $template = $this->template('meeting_reminder');

        $this->deleteJson(self::URL."/{$template->id}")->assertJsonValidationErrors('template');

        $this->assertNotSoftDeleted($template);
        $this->assertSame($template->id, $this->resolveAutomation('meeting_reminder')->id);
    }

    public function test_an_automation_template_can_be_retired_once_another_serves_its_purpose(): void
    {
        $this->actingAsAdmin();
        $original = $this->template('thank_you');

        $replacement = $this->postJson(self::URL, $this->payload(['purpose' => 'thank_you', 'tone' => 'Formal']))
            ->assertCreated()
            ->json('data.id');

        $this->patchTemplate($original, ['is_active' => false])->assertOk();

        // The endpoint still resolves: the requested tone is gone, so it falls back to the replacement.
        $this->assertSame((int) $replacement, $this->resolveAutomation('thank_you')->id);

        // Retired, it serves nothing — so it can now be removed without touching automation.
        $this->deleteJson(self::URL."/{$original->id}")->assertOk();
        $this->assertSoftDeleted($original);
    }

    public function test_an_automation_templates_purpose_is_locked(): void
    {
        $this->actingAsAdmin();

        $this->patchTemplate($this->template('proposal'), ['purpose' => 'thank_you'])->assertJsonValidationErrors('purpose');
    }

    public function test_an_automation_templates_content_can_still_be_edited(): void
    {
        $this->actingAsAdmin();
        $template = $this->template('proposal');

        $this->patchTemplate($template, ['subject' => 'A proposal for {{business_name}}'])->assertOk();

        $this->assertSame('A proposal for {{business_name}}', $this->resolveAutomation('proposal')->subject);
    }

    // ── Tenancy ───────────────────────────────────────────────────────────────────────────────

    public function test_another_tenants_template_is_not_found(): void
    {
        [$theirOrg] = $this->orgWithAdmin('theirs');
        [$ourOrg, $ourAdmin] = $this->orgWithAdmin('ours');

        $theirs = CurrentOrg::runAs($theirOrg->id, fn (): EmailTemplate => EmailTemplate::query()->where('purpose', 'Follow-up')->firstOrFail());
        $ourCount = CurrentOrg::runAs($ourOrg->id, fn (): int => EmailTemplate::query()->count());

        CurrentOrg::clear();
        Sanctum::actingAs($ourAdmin);

        $this->patchTemplate($theirs, ['subject' => 'Hijacked'])->assertNotFound();
        $this->deleteJson(self::URL."/{$theirs->id}")->assertNotFound();
        $this->getJson(self::URL)->assertOk()->assertJsonCount($ourCount, 'data');

        $this->assertNotSame('Hijacked', EmailTemplate::withoutGlobalScopes()->find($theirs->id)->subject);
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

    // ── Reseeding ─────────────────────────────────────────────────────────────────────────────

    public function test_reseeding_keeps_an_admins_edit(): void
    {
        $this->actingAsAdmin();
        $template = $this->template('Follow-up', 'Formal');
        $count = EmailTemplate::withTrashed()->count();

        $this->patchTemplate($template, ['subject' => 'Edited by an admin', 'is_active' => false])->assertOk();
        $this->seed(EmailTemplateSeeder::class);

        $this->assertSame('Edited by an admin', $template->fresh()->subject);
        $this->assertFalse($template->fresh()->is_active);
        $this->assertSame($count, EmailTemplate::withTrashed()->count());
    }

    public function test_reseeding_does_not_bring_back_a_deleted_template(): void
    {
        $this->actingAsAdmin();
        $template = $this->template('Follow-up', 'Casual');

        $this->deleteJson(self::URL."/{$template->id}")->assertOk();
        $this->seed(EmailTemplateSeeder::class);

        $this->assertFalse(EmailTemplate::query()->where('purpose', 'Follow-up')->where('tone', 'Casual')->exists());
        $this->assertSoftDeleted($template);
    }

    public function test_seeding_an_empty_org_still_installs_the_whole_catalog(): void
    {
        EmailTemplate::withTrashed()->forceDelete();

        $this->seed(EmailTemplateSeeder::class);

        foreach (EmailTemplateCatalog::COMPOSER_PURPOSES as $purpose) {
            foreach (EmailTemplateCatalog::TONES as $tone) {
                $this->assertTrue($this->template($purpose, $tone)->is_active);
            }
        }
        foreach (EmailTemplateCatalog::automationPurposes() as $purpose) {
            $this->assertTrue($this->resolveAutomation($purpose)->is_active);
        }
    }

    // ── The catalog cannot drift from what it mirrors ─────────────────────────────────────────

    public function test_the_catalogs_composer_purposes_are_the_composers_own(): void
    {
        $constant = new ReflectionClassConstant(EmailTemplateController::class, 'COMPOSER_PURPOSES');

        $this->assertSame($constant->getValue(), EmailTemplateCatalog::COMPOSER_PURPOSES);
    }

    public function test_the_catalogs_placeholders_are_the_renderers_whitelist(): void
    {
        $tokens = new ReflectionMethod(TemplateRenderer::class, 'tokens');
        $keys = array_keys($tokens->invoke(new TemplateRenderer, Lead::factory()->make(), null));

        sort($keys);
        $catalog = array_keys(EmailTemplateCatalog::TOKENS);
        sort($catalog);

        $this->assertSame($keys, $catalog);
    }

    public function test_every_seeded_template_fits_the_catalog(): void
    {
        foreach (EmailTemplate::query()->get() as $template) {
            $this->assertContains($template->purpose, EmailTemplateCatalog::purposes());
            $this->assertContains($template->tone, EmailTemplateCatalog::TONES);
            $this->assertSame([], EmailTemplateCatalog::unknownTokens($template->subject.' '.$template->body));
            $this->assertFalse(EmailTemplateCatalog::hasStrayBraces($template->subject.' '.$template->body));
        }
    }
}
