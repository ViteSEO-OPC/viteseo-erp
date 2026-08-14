<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Controllers\AutomationEmailController;
use App\Modules\Crm\Database\Seeders\EmailTemplateSeeder;
use App\Modules\Crm\Models\EmailTemplate;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Requests\AutomationSendEmailRequest;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * COMPOSER PURPOSE ALLOWLIST — GET /leads/{lead}/email-templates returns only the human-facing
 * purposes, never the machine-keyed automation templates.
 *
 * `crm_email_templates` holds both populations with NO column separating them: the composer's
 * Title Case purposes and the automation endpoint's snake_case keys
 * ({@see AutomationSendEmailRequest::PURPOSES}) live side by side, distinguished only by naming
 * convention. The BD dropdown was rendering all seven, so a BD could pick `meeting_reminder`.
 *
 * The filter is applied in PHP rather than SQL, and {@see test_introduction_survives_the_filter}
 * is why. The table is utf8mb4_unicode_ci — case-INSENSITIVE — so MySQL evaluates
 * 'introduction' = 'Introduction' as TRUE. The obvious fix,
 * `whereNotIn('purpose', AutomationSendEmailRequest::PURPOSES)`, therefore deletes all three human
 * Introduction templates from the composer as collateral, leaving a BD with only Follow-up and
 * Proposal Check-in. That regression is silent, plausible, and would survive review — so it gets a
 * dedicated test rather than a comment.
 *
 * Nothing here touches the automation path: no rows are deleted or deactivated, and the last test
 * exercises the real resolver to prove it.
 */
class ComposerTemplatePurposesTest extends TestCase
{
    use RefreshDatabase;

    private User $bd;

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        // The real template catalog — both populations, exactly as production holds them.
        $this->seed(EmailTemplateSeeder::class);

        $this->bd = User::factory()->create(['status' => 'active']);
        $this->bd->assignRole('crm_bd');
        $this->lead = Lead::factory()->create([
            'assigned_to' => $this->bd->id,
            'created_by' => $this->bd->id,
            'cold_calling' => 'not-called',
        ]);
    }

    /** @return list<string> */
    private function purposes(): array
    {
        $response = $this->actingAs($this->bd)
            ->getJson("/api/v1/crm/leads/{$this->lead->id}/email-templates")
            ->assertOk();

        return array_values(array_unique(array_column($response->json('data'), 'purpose')));
    }

    // ── The composer purposes survive ───────────────────────────────────────────────────────────

    /** @return array<string, array{0: string}> */
    public static function composerPurposes(): array
    {
        return [
            'Proposal Check-in' => ['Proposal Check-in'],
            'Follow-up' => ['Follow-up'],
            'Introduction' => ['Introduction'],
        ];
    }

    #[DataProvider('composerPurposes')]
    public function test_composer_purposes_remain_available(string $purpose): void
    {
        $this->assertContains($purpose, $this->purposes());
    }

    /**
     * THE collation regression lock. `Introduction` is the one composer purpose whose lowercase form
     * is also an automation key, so it is the only one a SQL-side filter would silently take with it.
     * Asserts the full tone set survives, not merely the purpose: a partial match would mean the
     * filter clipped variants.
     */
    public function test_introduction_survives_the_filter(): void
    {
        $response = $this->actingAs($this->bd)
            ->getJson("/api/v1/crm/leads/{$this->lead->id}/email-templates")
            ->assertOk();

        $tones = array_column(
            array_filter($response->json('data'), fn (array $t): bool => $t['purpose'] === 'Introduction'),
            'tone',
        );

        sort($tones);
        $this->assertSame(['Casual', 'Formal', 'Warm & professional'], $tones);
    }

    // ── The automation keys are excluded ────────────────────────────────────────────────────────

    /** @return array<string, array{0: string}> */
    public static function automationPurposes(): array
    {
        return [
            'meeting_reminder' => ['meeting_reminder'],
            'proposal' => ['proposal'],
            'proposal_follow_up' => ['proposal_follow_up'],
            'thank_you' => ['thank_you'],
        ];
    }

    #[DataProvider('automationPurposes')]
    public function test_automation_purposes_are_excluded(string $purpose): void
    {
        $this->assertNotContains($purpose, $this->purposes());
    }

    /** The whole rule in one pass — exactly the three human purposes, nothing else. */
    public function test_the_composer_sees_only_its_own_purposes(): void
    {
        $purposes = $this->purposes();
        sort($purposes);

        $this->assertSame(['Follow-up', 'Introduction', 'Proposal Check-in'], $purposes);
    }

    // ── Shape ───────────────────────────────────────────────────────────────────────────────────

    /**
     * `filter()` preserves the original keys, so without `values()` the payload serialises as a JSON
     * OBJECT keyed "0","1","3"… and the frontend's `templates.map()` throws. Asserts a real list.
     */
    public function test_the_payload_is_a_json_array_not_an_object(): void
    {
        $raw = $this->actingAs($this->bd)
            ->getJson("/api/v1/crm/leads/{$this->lead->id}/email-templates")
            ->assertOk()
            ->getContent();

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsList($decoded['data'], 'the composer maps over this — it must be a list');
        $this->assertNotEmpty($decoded['data']);
    }

    // ── Automation is untouched ─────────────────────────────────────────────────────────────────

    /**
     * The read-side filter must be invisible to automation. This drives the REAL private resolver on
     * the REAL controller (reflection) rather than restating its query, so the test cannot drift from
     * the implementation — and deliberately stops there: invoking the endpoint would drag in the send
     * pipeline, which this slice does not touch.
     */
    #[DataProvider('everyAutomationPurpose')]
    public function test_automation_still_resolves_every_purpose(string $purpose): void
    {
        $resolve = new ReflectionMethod(AutomationEmailController::class, 'resolveTemplate');

        $template = $resolve->invoke(new AutomationEmailController(), $purpose, 'Warm & professional');

        $this->assertInstanceOf(EmailTemplate::class, $template);
        $this->assertTrue($template->is_active);
    }

    /** @return array<string, array{0: string}> */
    public static function everyAutomationPurpose(): array
    {
        return array_combine(
            AutomationSendEmailRequest::PURPOSES,
            array_map(fn (string $p): array => [$p], AutomationSendEmailRequest::PURPOSES),
        );
    }

    /**
     * No row was removed or deactivated — the filter is read-side only. Compared before/after rather
     * than against a literal: the seeded total is collation-dependent (MySQL's case-insensitive
     * `utf8mb4_unicode_ci` folds the seeder's `introduction` upsert into the existing `Introduction`
     * row and yields one row fewer than SQLite), and this test is about the delta, not the total.
     */
    public function test_the_template_catalog_is_unchanged(): void
    {
        $before = EmailTemplate::query()->active()->count();

        $this->actingAs($this->bd)
            ->getJson("/api/v1/crm/leads/{$this->lead->id}/email-templates")
            ->assertOk();

        $this->assertSame($before, EmailTemplate::query()->active()->count());
    }

    /**
     * The comparison must stay CASE-SENSITIVE. Where both spellings exist as separate rows, the
     * composer takes `Introduction` and leaves `introduction` — loosening the filter to a
     * case-insensitive comparison (`in_array` without strict, `strtolower`, a SQL `whereIn`) would
     * start leaking the automation key into the BD's dropdown, and this fails the moment it does.
     *
     * Guarded because the two rows only coexist on a case-sensitive store; on MySQL the seeder's
     * upsert folds them into one, and there is then nothing to distinguish.
     */
    public function test_the_filter_is_case_sensitive(): void
    {
        $lowercase = EmailTemplate::query()->where('purpose', 'introduction')->first();

        if ($lowercase === null || $lowercase->purpose !== 'introduction') {
            $this->markTestSkipped('case-insensitive collation folds the two spellings into one row');
        }

        $this->assertContains('Introduction', $this->purposes());
        $this->assertNotContains('introduction', $this->purposes());
    }
}
