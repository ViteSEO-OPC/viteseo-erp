<?php

namespace Tests\Feature\Crm;

use App\Models\Org;
use App\Models\User;
use App\Modules\Crm\Controllers\AutomationEmailController;
use App\Modules\Crm\Database\Seeders\EmailTemplateSeeder;
use App\Modules\Crm\Models\EmailTemplate;
use App\Modules\Crm\Models\EmailTemplateOption;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Requests\AutomationSendEmailRequest;
use App\Modules\Crm\Services\Outreach\EmailTemplateOptionService;
use App\Modules\Crm\Support\EmailTemplateCatalog;
use App\Services\OrgOnboardingService;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Custom email-template purposes and tones: each organization's own additions to the catalog.
 *
 * What must never happen carries the weight: a custom purpose that the automation resolver's case-insensitive lookup
 * could find for an automation key, a value visible to another organization, a duplicate however spelled, a deleted
 * value leaving a template stranded, or any existing template changing because custom values exist. Templates keep
 * storing plain strings, so a custom value on a template is exercised through the same endpoints as a built-in one.
 */
class EmailTemplateOptionTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/crm/settings/email-template-options';

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

    private function addOption(string $type, string $value): TestResponse
    {
        return $this->postJson(self::URL, ['type' => $type, 'value' => $value]);
    }

    private function createTemplate(string $purpose, string $tone, bool $active = true): TestResponse
    {
        return $this->postJson(self::TEMPLATES_URL, [
            'purpose' => $purpose,
            'tone' => $tone,
            'subject' => $purpose.' for {{business_name}}',
            'body' => "Hi {{first_name}},\n\n{$purpose} · {$tone}.",
            'is_active' => $active,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function snapshot(): array
    {
        return EmailTemplate::withTrashed()->orderBy('id')
            ->get(['id', 'purpose', 'tone', 'subject', 'body', 'is_active', 'content', 'email_design_id', 'updated_at', 'deleted_at'])
            ->toArray();
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

    public function test_only_management_roles_manage_custom_values(): void
    {
        $this->actingAsRole();
        $id = $this->addOption('purpose', 'Sales Pitch')->assertCreated()->json('data.id');

        $this->actingAsRole('crm_bd');
        $this->getJson(self::URL)->assertForbidden();
        $this->addOption('tone', 'Upbeat')->assertForbidden();
        $this->deleteJson(self::URL."/{$id}")->assertForbidden();

        $this->assertSame(1, EmailTemplateOption::query()->count());
    }

    public function test_custom_values_are_cleaned_saved_and_listed_beside_the_built_in_ones(): void
    {
        $this->actingAsRole();

        $purpose = $this->addOption('purpose', '  Sales '.mb_chr(0xA0).'  Pitch ')
            ->assertCreated()
            ->assertJsonPath('data.type', 'purpose')
            ->assertJsonPath('data.value', 'Sales Pitch');
        $this->addOption('tone', 'Very   friendly')->assertCreated()->assertJsonPath('data.value', 'Very friendly');
        $this->assertSame('sales pitch', EmailTemplateOption::query()->where('type', 'purpose')->sole()->normalized_value);

        $data = $this->getJson(self::URL)->assertOk()->json('data');
        $this->assertSame([...EmailTemplateCatalog::purposes(), 'Sales Pitch'], array_column($data['purposes'], 'value'));
        $this->assertSame(
            ['value' => 'Sales Pitch', 'kind' => 'composer', 'custom' => true, 'optionId' => (string) $purpose->json('data.id'), 'templatesCount' => 0],
            collect($data['purposes'])->firstWhere('value', 'Sales Pitch'),
        );
        $this->assertFalse(collect($data['purposes'])->firstWhere('value', 'Follow-up')['custom']);
        $this->assertSame([...EmailTemplateCatalog::TONES, 'Very friendly'], array_column($data['tones'], 'value'));

        $meta = $this->getJson(self::TEMPLATES_URL)->assertOk()->json('meta');
        $this->assertContains('Sales Pitch', array_column($meta['purposes'], 'value'));
        $this->assertSame([...EmailTemplateCatalog::TONES, 'Very friendly'], $meta['tones']);
        $this->assertSame('Very friendly', $meta['customTones'][0]['value']);
    }

    public function test_the_length_limits_are_inclusive(): void
    {
        $this->actingAsRole();

        $this->addOption('purpose', 'Hi')->assertCreated();
        $this->addOption('tone', str_repeat('y', EmailTemplateOptionService::MAX_LENGTH))->assertCreated();
    }

    /** @return array<string, array{string, string}> */
    public static function invalidValues(): array
    {
        return [
            'empty' => ['', EmailTemplateOptionService::EMPTY],
            'only spaces' => ['    ', EmailTemplateOptionService::EMPTY],
            'one character' => ['a', EmailTemplateOptionService::LENGTH],
            '61 characters' => [str_repeat('x', 61), EmailTemplateOptionService::LENGTH],
            'placeholder braces' => ['{{first_name}}', EmailTemplateOptionService::INVALID],
            'single braces' => ['Hi {there}', EmailTemplateOptionService::INVALID],
            'markup' => ['<b>Bold</b>', EmailTemplateOptionService::INVALID],
            'tab' => ['Tab'.chr(9).'bed', EmailTemplateOptionService::INVALID],
            'newline' => ['Two'.chr(10).'lines', EmailTemplateOptionService::INVALID],
            'bidi override' => ['Right'.mb_chr(0x202E).'left', EmailTemplateOptionService::INVALID],
            'zero-width space' => ['Zero'.mb_chr(0x200B).'width', EmailTemplateOptionService::INVALID],
            'backslash' => ['Back'.chr(92).'slash', EmailTemplateOptionService::INVALID],
            'backtick' => ['Back`tick', EmailTemplateOptionService::INVALID],
            'punctuation only' => ['--', EmailTemplateOptionService::INVALID],
        ];
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_values_are_refused(string $value, string $message): void
    {
        $this->actingAsRole();

        foreach (EmailTemplateOptionService::TYPES as $type) {
            $this->addOption($type, $value)->assertUnprocessable()->assertJsonPath('errors.value.0', $message);
        }

        $this->assertSame(0, EmailTemplateOption::withTrashed()->count());
    }

    public function test_a_value_can_be_added_once_per_list_however_it_is_spelled(): void
    {
        $this->actingAsRole();
        $this->addOption('purpose', 'Sales Pitch')->assertCreated();

        foreach (['Sales Pitch', 'sales pitch', 'SALES_PITCH', 'Sales-Pitch', '  sales   -  pitch '] as $spelling) {
            $this->addOption('purpose', $spelling)
                ->assertUnprocessable()
                ->assertJsonPath('errors.value.0', sprintf(EmailTemplateOptionService::DUPLICATE, 'purpose', 'Sales Pitch'));
        }

        // Purposes and tones are separate lists.
        $this->addOption('tone', 'Sales Pitch')->assertCreated();
        $this->assertSame(2, EmailTemplateOption::query()->count());
    }

    /** @return array<string, array{string, bool}> */
    public static function builtInPurposes(): array
    {
        return [
            'Follow-up' => ['Follow-up', false],
            'follow up' => ['follow up', false],
            'FOLLOW_UP' => ['FOLLOW_UP', false],
            'proposal check-in' => ['proposal check-in', false],
            'introduction' => ['introduction', false],
            'proposal' => ['proposal', true],
            'Proposal' => ['Proposal', true],
            'Proposal Follow Up' => ['Proposal Follow Up', true],
            'Meeting Reminder' => ['Meeting Reminder', true],
            'meeting-reminder' => ['meeting-reminder', true],
            'Thank You' => ['Thank You', true],
            'Closing (reserved)' => ['Closing', true],
        ];
    }

    #[DataProvider('builtInPurposes')]
    public function test_a_custom_purpose_cannot_take_a_built_in_purpose_or_an_automation_key(string $value, bool $automation): void
    {
        $this->actingAsRole();

        $message = (string) $this->addOption('purpose', $value)->assertUnprocessable()->json('errors.value.0');

        $this->assertStringContainsString($automation ? 'reserved for the automation purpose' : 'already a built-in purpose', $message);
        $this->assertSame(0, EmailTemplateOption::withTrashed()->count());
    }

    public function test_every_automation_key_is_protected_in_every_spelling(): void
    {
        $this->actingAsRole();

        foreach ([...AutomationSendEmailRequest::PURPOSES, ...EmailTemplateOptionService::RESERVED_PURPOSE_KEYS] as $key) {
            foreach ([$key, strtoupper($key), str_replace('_', ' ', $key), str_replace('_', '-', ucwords($key, '_'))] as $spelling) {
                $this->addOption('purpose', $spelling)->assertUnprocessable()->assertJsonValidationErrors(['value']);
            }
        }

        $this->assertSame(0, EmailTemplateOption::withTrashed()->count());
    }

    public function test_a_custom_tone_cannot_take_a_built_in_tone(): void
    {
        $this->actingAsRole();

        foreach (['casual', 'FORMAL', 'warm & professional', 'Warm  &  Professional'] as $value) {
            $this->assertStringContainsString(
                'already a built-in tone',
                (string) $this->addOption('tone', $value)->assertUnprocessable()->json('errors.value.0'),
            );
        }

        $this->assertSame(0, EmailTemplateOption::withTrashed()->count());
    }

    public function test_custom_values_are_reused_across_templates_like_built_in_ones(): void
    {
        $this->actingAsRole();
        $this->addOption('purpose', 'Sales Pitch')->assertCreated();
        $this->addOption('tone', 'Upbeat')->assertCreated();

        $this->createTemplate('Sales Pitch', 'Upbeat')->assertCreated()->assertJsonPath('data.kind', 'composer');
        $this->createTemplate('Sales Pitch', 'Formal')->assertCreated();
        $this->createTemplate('Follow-up', 'Upbeat')->assertCreated();
        $this->createTemplate('Sales Pitch', 'Upbeat')->assertUnprocessable()->assertJsonValidationErrors(['tone']);

        $existing = EmailTemplate::query()->where('purpose', 'Introduction')->where('tone', 'Casual')->firstOrFail();
        $this->patchJson(self::TEMPLATES_URL."/{$existing->id}", ['purpose' => 'Sales Pitch'])->assertOk()->assertJsonPath('data.purpose', 'Sales Pitch');

        $data = $this->getJson(self::URL)->assertOk()->json('data');
        $this->assertSame(3, collect($data['purposes'])->firstWhere('value', 'Sales Pitch')['templatesCount']);
        $this->assertSame(2, collect($data['tones'])->firstWhere('value', 'Upbeat')['templatesCount']);

        // An automation template stays one: a custom purpose is a composer purpose.
        $automation = EmailTemplate::query()->where('purpose', 'thank_you')->firstOrFail();
        $this->patchJson(self::TEMPLATES_URL."/{$automation->id}", ['purpose' => 'Sales Pitch'])->assertUnprocessable()->assertJsonValidationErrors(['purpose']);

        // Anything outside the organization's catalog is still refused.
        $this->createTemplate('Made Up', 'Formal')->assertUnprocessable()->assertJsonValidationErrors(['purpose']);
        $this->createTemplate('Sales Pitch', 'Made Up')->assertUnprocessable()->assertJsonValidationErrors(['tone']);
    }

    public function test_custom_values_belong_to_one_organization(): void
    {
        [, $theirAdmin] = $this->orgWithAdmin('theirs');
        [, $ourAdmin] = $this->orgWithAdmin('ours');

        CurrentOrg::clear();
        Sanctum::actingAs($theirAdmin);
        $theirs = $this->addOption('purpose', 'Sales Pitch')->assertCreated()->json('data.id');
        $this->addOption('tone', 'Upbeat')->assertCreated();
        $this->createTemplate('Sales Pitch', 'Upbeat')->assertCreated();

        CurrentOrg::clear();
        Sanctum::actingAs($ourAdmin);
        $data = $this->getJson(self::URL)->assertOk()->json('data');
        $this->assertNotContains('Sales Pitch', array_column($data['purposes'], 'value'));
        $this->assertNotContains('Upbeat', array_column($data['tones'], 'value'));
        $this->assertNotContains('Sales Pitch', array_column($this->getJson(self::TEMPLATES_URL)->assertOk()->json('meta.purposes'), 'value'));
        $this->createTemplate('Sales Pitch', 'Formal')->assertUnprocessable()->assertJsonValidationErrors(['purpose']);
        $this->deleteJson(self::URL."/{$theirs}")->assertNotFound();

        // Each organization keeps its own list, so the same value can exist in both.
        $this->addOption('purpose', 'Sales Pitch')->assertCreated();
        $this->assertSame(2, EmailTemplateOption::withoutGlobalScopes()->where('type', 'purpose')->count());
        $this->assertNotNull(EmailTemplateOption::withoutGlobalScopes()->find($theirs));
    }

    public function test_the_outreach_composer_offers_custom_purposes_and_tones_beside_every_built_in_purpose(): void
    {
        $this->actingAsRole();
        $this->addOption('purpose', 'Sales Pitch')->assertCreated();
        $this->addOption('purpose', 'Unused Purpose')->assertCreated();
        $this->addOption('tone', 'Upbeat')->assertCreated();
        $this->createTemplate('Sales Pitch', 'Upbeat')->assertCreated();
        $this->createTemplate('Follow-up', 'Upbeat')->assertCreated();
        $this->createTemplate('Sales Pitch', 'Formal', active: false)->assertCreated();

        $bd = User::factory()->create(['status' => 'active']);
        $bd->assignRole('crm_bd');
        $lead = Lead::factory()->create(['assigned_to' => $bd->id, 'created_by' => $bd->id, 'cold_calling' => 'not-called']);

        $rows = $this->actingAs($bd)->getJson("/api/v1/crm/leads/{$lead->id}/email-templates")->assertOk()->json('data');

        $purposes = array_values(array_unique(array_column($rows, 'purpose')));
        sort($purposes);
        $expected = [...EmailTemplateCatalog::COMPOSER_PURPOSES, 'Sales Pitch'];
        sort($expected);
        $this->assertSame($expected, $purposes, 'every built-in composer purpose and the used custom one, and no automation key');

        $pitch = collect($rows)->where('purpose', 'Sales Pitch')->values();
        $this->assertCount(1, $pitch, 'the inactive custom template stays out, as any inactive template does');
        $this->assertSame('Upbeat', $pitch[0]['tone']);
        $this->assertStringStartsWith('Sales Pitch for ', $pitch[0]['subject']);
        $this->assertStringNotContainsString('{{', $pitch[0]['subject'].$pitch[0]['body']);
        $this->assertSame(2, collect($rows)->where('tone', 'Upbeat')->count());
        foreach (AutomationSendEmailRequest::PURPOSES as $key) {
            $this->assertNotContains($key, $purposes);
        }
    }

    public function test_automation_resolves_exactly_the_same_templates_after_custom_values_are_added(): void
    {
        $this->actingAsRole();
        $resolve = new ReflectionMethod(AutomationEmailController::class, 'resolveTemplate');
        $resolved = fn (): array => array_map(
            fn (string $purpose): int => $resolve->invoke(new AutomationEmailController, $purpose, 'Warm & professional')->id,
            AutomationSendEmailRequest::PURPOSES,
        );
        $before = $resolved();

        $this->addOption('purpose', 'Proposal Pitch')->assertCreated();
        $this->addOption('purpose', 'Thanks Again')->assertCreated();
        $this->addOption('tone', 'Upbeat')->assertCreated();
        $this->createTemplate('Proposal Pitch', 'Warm & professional')->assertCreated();
        $this->createTemplate('Thanks Again', 'Warm & professional')->assertCreated();

        $this->assertSame($before, $resolved());
    }

    public function test_an_unused_value_can_be_deleted_and_added_again(): void
    {
        $this->actingAsRole();
        $id = $this->addOption('purpose', 'Sales Pitch')->assertCreated()->json('data.id');

        $this->deleteJson(self::URL."/{$id}")->assertOk();
        $this->assertSoftDeleted('crm_email_template_options', ['id' => $id]);
        $this->assertNotContains('Sales Pitch', array_column($this->getJson(self::URL)->json('data.purposes'), 'value'));
        $this->createTemplate('Sales Pitch', 'Formal')->assertUnprocessable()->assertJsonValidationErrors(['purpose']);

        $again = $this->addOption('purpose', 'sales-pitch')->assertCreated()->assertJsonPath('data.value', 'sales-pitch');
        $this->assertSame($id, $again->json('data.id'), 'the deleted row is restored, never duplicated');
        $this->assertSame(1, EmailTemplateOption::withTrashed()->count());
    }

    public function test_a_value_a_template_uses_cannot_be_deleted(): void
    {
        $this->actingAsRole();
        $purpose = $this->addOption('purpose', 'Sales Pitch')->json('data.id');
        $tone = $this->addOption('tone', 'Upbeat')->json('data.id');
        $inactive = $this->createTemplate('Sales Pitch', 'Upbeat', active: false)->assertCreated()->json('data.id');
        $active = $this->createTemplate('Sales Pitch', 'Formal')->assertCreated()->json('data.id');

        $this->deleteJson(self::URL."/{$purpose}")
            ->assertUnprocessable()
            ->assertJsonPath('errors.option.0', sprintf(EmailTemplateOptionService::IN_USE, 'purpose', 'Sales Pitch', 2, 'templates', 'them', 'purpose'));
        $this->deleteJson(self::URL."/{$tone}")
            ->assertUnprocessable()
            ->assertJsonPath('errors.option.0', sprintf(EmailTemplateOptionService::IN_USE, 'tone', 'Upbeat', 1, 'template', 'it', 'tone'));
        $this->assertSame(2, EmailTemplateOption::query()->count());

        $this->deleteJson(self::TEMPLATES_URL."/{$inactive}")->assertOk();
        $this->deleteJson(self::TEMPLATES_URL."/{$active}")->assertOk();
        $this->deleteJson(self::URL."/{$purpose}")->assertOk();
        $this->deleteJson(self::URL."/{$tone}")->assertOk();
    }

    public function test_existing_templates_are_untouched_by_custom_values(): void
    {
        $this->actingAsRole();
        $before = $this->snapshot();

        $id = $this->addOption('purpose', 'Sales Pitch')->assertCreated()->json('data.id');
        $this->addOption('tone', 'Upbeat')->assertCreated();
        $this->getJson(self::URL)->assertOk();
        $this->getJson(self::TEMPLATES_URL)->assertOk();
        $this->deleteJson(self::URL."/{$id}")->assertOk();

        $this->assertSame($before, $this->snapshot());
    }
}
