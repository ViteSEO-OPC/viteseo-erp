<?php

namespace Tests\Feature\Crm;

use App\Models\Org;
use App\Models\User;
use App\Modules\Crm\Database\Seeders\EmailTemplateSeeder;
use App\Modules\Crm\Models\EmailDesign;
use App\Modules\Crm\Models\EmailTemplate;
use App\Modules\Crm\Services\Outreach\EmailDesignService;
use App\Modules\Crm\Services\Outreach\EmailTemplateDesignService;
use App\Services\OrgOnboardingService;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Tests\Unit\Crm\EmailDesign\BuildsEmailDesigns;

/**
 * "Apply to all templates": one confirmed action points every DESIGNED template in the organization at a design.
 *
 * What must never happen carries the weight: a plain-text template converted, another organization's template
 * touched, the design's JSON copied into a template, or a partial change when the confirmed count is stale or the
 * design would break a template.
 */
class EmailDesignApplyToAllTest extends TestCase
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

    private function actingAsRole(string $role = 'crm_admin'): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function template(string $purpose, string $tone): EmailTemplate
    {
        return EmailTemplate::query()->where('purpose', $purpose)->where('tone', $tone)->firstOrFail();
    }

    /** @param  array<string, mixed>|null  $layout */
    private function design(string $name, ?array $layout = null): int
    {
        return (int) $this->postJson(self::URL, ['name' => $name, 'layout' => json_encode($layout ?? $this->layout())])
            ->assertCreated()->json('data.id');
    }

    /** @param  array<string, mixed>|null  $content */
    private function makeDesigned(EmailTemplate $template, ?int $designId = null, ?array $content = null): void
    {
        $this->patchJson(self::TEMPLATES_URL.'/'.$template->id, array_filter([
            'content' => json_encode($content ?? $this->content()),
            'email_design_id' => $designId,
        ], fn ($value) => $value !== null))->assertOk();
    }

    /** @return list<array<string, mixed>> */
    private function plainTextSnapshot(): array
    {
        return EmailTemplate::query()->whereNull('content')->orderBy('id')->get(['id', 'subject', 'body', 'email_design_id', 'updated_at'])->toArray();
    }

    /** Three designed templates: two in the default design, one already in $shared. */
    private function threeDesigned(int $shared): array
    {
        $this->makeDesigned($first = $this->template('Follow-up', 'Casual'));
        $this->makeDesigned($second = $this->template('Follow-up', 'Formal'));
        $this->makeDesigned($third = $this->template('Introduction', 'Casual'), $shared);

        return [$first->fresh(), $second->fresh(), $third->fresh()];
    }

    /** @return array{0: Org, 1: User} */
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

    public function test_the_summary_counts_what_apply_to_all_would_change(): void
    {
        $this->actingAsRole();
        $shared = $this->design('Shared');
        $this->threeDesigned($shared);
        $total = EmailTemplate::query()->count();

        $this->getJson(self::URL.'/apply-to-all-summary?email_design_id='.$shared)->assertOk()->assertExactJson(['data' => [
            'designedTemplates' => 3, 'alreadyUsing' => 1, 'switching' => 2, 'plainTextTemplates' => $total - 3,
        ]]);

        $this->getJson(self::URL.'/apply-to-all-summary')->assertOk()->assertExactJson(['data' => [
            'designedTemplates' => 3, 'alreadyUsing' => 0, 'switching' => 3, 'plainTextTemplates' => $total - 3,
        ]]);
    }

    public function test_apply_to_all_points_every_designed_template_at_one_design_without_copying_it(): void
    {
        $this->actingAsRole();
        $shared = $this->design('Shared', $this->layout(null, [$this->footer(['content' => $this->doc($this->para($this->text('Shared footer v1')))])]));
        [$first, , $third] = $this->threeDesigned($shared);
        $plainBefore = $this->plainTextSnapshot();
        $designsBefore = EmailDesign::query()->count();

        $this->travel(5)->minutes();
        $this->patchJson(self::URL.'/'.$shared, ['apply_to_all' => true, 'apply_to_all_count' => 3])->assertOk()->assertJsonPath('data.templatesCount', 3);

        $design = EmailDesign::query()->findOrFail($shared);
        $rendering = app(EmailTemplateDesignService::class);
        $designed = EmailTemplate::query()->whereNotNull('content')->get();

        $this->assertCount(3, $designed);
        foreach ($designed as $template) {
            $this->assertSame($shared, (int) $template->email_design_id);
            $this->assertSame($rendering->plainTextBody($design->layout, $template->content), $template->body);
            $this->assertStringContainsString('Shared footer v1', $template->body);
            $this->assertSame([], array_diff(array_keys($template->content), ['version', 'blocks', 'preheader', 'sign_off', 'style_overrides']), 'no design JSON in a template');
        }

        $this->assertSame($plainBefore, $this->plainTextSnapshot(), 'plain-text templates are untouched');
        $this->assertSame($designsBefore, EmailDesign::query()->count(), 'the design is not duplicated');
        $this->assertTrue($designed->firstWhere('id', $first->id)->updated_at->gt($first->updated_at), 'a template that switched design was edited');
        $this->assertTrue($designed->firstWhere('id', $third->id)->updated_at->eq($third->updated_at), 'a template already using the design was not');
        $this->assertSame(0, app(EmailDesignService::class)->defaultDesign()->templates()->count());
    }

    public function test_a_new_design_can_be_applied_to_all_as_it_is_created(): void
    {
        $this->actingAsRole();
        $this->makeDesigned($this->template('Follow-up', 'Casual'));
        $this->makeDesigned($this->template('Follow-up', 'Formal'));
        $plainBefore = $this->plainTextSnapshot();

        $id = $this->postJson(self::URL, [
            'name' => 'ViteSEO Default', 'layout' => json_encode($this->layout()), 'apply_to_all' => true, 'apply_to_all_count' => 2,
        ])->assertCreated()->assertJsonPath('data.templatesCount', 2)->json('data.id');

        $this->assertSame([(int) $id], EmailTemplate::query()->whereNotNull('content')->pluck('email_design_id')->unique()->map(fn ($v) => (int) $v)->values()->all());
        $this->assertSame($plainBefore, $this->plainTextSnapshot());
    }

    public function test_a_stale_confirmed_count_refuses_and_changes_nothing(): void
    {
        $this->actingAsRole();
        $shared = $this->design('Shared');
        [$first, $second] = $this->threeDesigned($shared);

        $this->patchJson(self::URL.'/'.$shared, ['name' => 'Renamed', 'apply_to_all' => true, 'apply_to_all_count' => 2])
            ->assertUnprocessable()
            ->assertJsonPath('errors.apply_to_all_count.0', sprintf(EmailDesignService::APPLY_COUNT_CHANGED, 2, 3));

        $this->assertSame('Shared', EmailDesign::query()->findOrFail($shared)->name);
        $this->assertSame($first->email_design_id, $first->fresh()->email_design_id);
        $this->assertSame($second->email_design_id, $second->fresh()->email_design_id);
    }

    public function test_applying_needs_the_confirmed_count(): void
    {
        $this->actingAsRole();
        $shared = $this->design('Shared');

        $this->patchJson(self::URL.'/'.$shared, ['apply_to_all' => true])->assertUnprocessable()->assertJsonValidationErrors(['apply_to_all_count']);
    }

    public function test_a_design_that_would_break_a_designed_template_is_not_applied(): void
    {
        $this->actingAsRole();
        $long = $this->content([
            $this->textBlock(['id' => 'long-1', 'content' => $this->doc($this->para($this->text(str_repeat('a', 4800))))]),
            $this->textBlock(['id' => 'long-2', 'content' => $this->doc($this->para($this->text(str_repeat('b', 4800))))]),
        ]);
        $this->makeDesigned($template = $this->template('Follow-up', 'Casual'), null, $long);
        $defaultId = $template->fresh()->email_design_id;

        $wide = $this->design('Wide footer', $this->layout([], [$this->footer(['content' => $this->doc($this->para($this->text(str_repeat('c', 4000))))])]));

        $response = $this->patchJson(self::URL.'/'.$wide, ['apply_to_all' => true, 'apply_to_all_count' => 1])->assertUnprocessable();
        $this->assertStringContainsString('This change would break the template', (string) $response->json('errors.apply_to_all.0'));
        $this->assertSame($defaultId, $template->fresh()->email_design_id);
    }

    public function test_other_organizations_are_never_touched(): void
    {
        [$theirOrg, $theirAdmin] = $this->orgWithAdmin('theirs');
        [$ourOrg, $ourAdmin] = $this->orgWithAdmin('ours');

        $designed = function (int $orgId, string $subject): EmailTemplate {
            return CurrentOrg::runAs($orgId, fn (): EmailTemplate => EmailTemplate::create([
                'purpose' => 'thank_you', 'tone' => 'Formal', 'subject' => $subject, 'body' => 'unchanged',
                'content' => $this->content(), 'email_design_id' => app(EmailDesignService::class)->defaultDesign()->id, 'is_active' => false,
            ]));
        };
        $theirs = $designed($theirOrg->id, 'Theirs');
        $designed($ourOrg->id, 'Ours');
        $theirDesign = CurrentOrg::runAs($theirOrg->id, fn () => EmailDesign::create(['name' => 'Theirs', 'is_default' => false, 'layout' => $this->layout()]));

        CurrentOrg::clear();
        Sanctum::actingAs($ourAdmin);

        $this->getJson(self::URL.'/apply-to-all-summary?email_design_id='.$theirDesign->id)->assertNotFound();
        $ours = $this->design('Ours');
        $this->getJson(self::URL.'/apply-to-all-summary?email_design_id='.$ours)->assertOk()->assertJsonPath('data.designedTemplates', 1);
        $this->patchJson(self::URL.'/'.$ours, ['apply_to_all' => true, 'apply_to_all_count' => 1])->assertOk();
        $this->patchJson(self::URL.'/'.$theirDesign->id, ['apply_to_all' => true, 'apply_to_all_count' => 1])->assertNotFound();

        $after = EmailTemplate::withoutGlobalScopes()->findOrFail($theirs->id);
        $this->assertSame($theirs->email_design_id, $after->email_design_id);
        $this->assertSame('unchanged', $after->body);
        $this->assertSame($ours, (int) EmailTemplate::withoutGlobalScopes()->where('org_id', $ourOrg->id)->whereNotNull('content')->sole()->email_design_id);
        $this->assertNotNull($theirAdmin);
    }

    public function test_a_bd_can_neither_see_nor_apply(): void
    {
        $this->actingAsRole();
        $shared = $this->design('Shared');

        $this->actingAsRole('crm_bd');
        $this->getJson(self::URL.'/apply-to-all-summary')->assertForbidden();
        $this->patchJson(self::URL.'/'.$shared, ['apply_to_all' => true, 'apply_to_all_count' => 0])->assertForbidden();
    }

    public function test_changing_the_shared_design_afterwards_still_reaches_every_template(): void
    {
        $this->actingAsRole();
        $shared = $this->design('Shared');
        $this->threeDesigned($shared);
        $this->patchJson(self::URL.'/'.$shared, ['apply_to_all' => true, 'apply_to_all_count' => 3])->assertOk();
        $plainBefore = $this->plainTextSnapshot();

        $this->patchJson(self::URL.'/'.$shared, [
            'layout' => json_encode($this->layout(null, [$this->footer(['content' => $this->doc($this->para($this->text('Shared footer v2')))])])),
        ])->assertOk();

        $bodies = EmailTemplate::query()->whereNotNull('content')->pluck('body');
        $this->assertCount(3, $bodies);
        foreach ($bodies as $body) {
            $this->assertStringContainsString('Shared footer v2', $body);
        }
        $this->assertSame($plainBefore, $this->plainTextSnapshot());
    }
}
