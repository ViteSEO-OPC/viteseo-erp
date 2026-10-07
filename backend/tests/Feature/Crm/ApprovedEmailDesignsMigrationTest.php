<?php

namespace Tests\Feature\Crm;

use App\Models\Org;
use App\Modules\Crm\Services\Outreach\EmailTemplateDesignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The data migration that gives the provider org the approved Revora Quotation and GCamp Proposal designs, their
 * active templates and their custom purposes: matched by what each record is, never by id, and safe to run again.
 */
class ApprovedEmailDesignsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const NAMES = ['Revora Quotation', 'GCamp Proposal'];

    private function migrate(): void
    {
        (require base_path('app/Modules/Crm/Migrations/2026_10_08_000001_seed_approved_revora_and_gcamp_email_designs.php'))->up();
    }

    private function providerId(): int
    {
        return (int) DB::table('orgs')->orderBy('id')->value('id');
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'designs' => DB::table('crm_email_designs')->count(),
            'templates' => DB::table('crm_email_templates')->count(),
            'options' => DB::table('crm_email_template_options')->count(),
        ];
    }

    public function test_it_creates_both_designs_their_active_templates_and_purposes_in_the_provider_org(): void
    {
        $this->migrate();
        $org = $this->providerId();
        $designs = app(EmailTemplateDesignService::class);

        foreach (self::NAMES as $name) {
            $design = DB::table('crm_email_designs')->where('org_id', $org)->where('name', $name)->whereNull('deleted_at')->sole();
            $template = DB::table('crm_email_templates')->where('org_id', $org)->where('purpose', $name)->where('tone', 'Warm & professional')->sole();
            $layout = json_decode($design->layout, true);
            $content = json_decode($template->content, true);

            $this->assertFalse((bool) $design->is_default);
            $this->assertSame($design->id, $template->email_design_id);
            $this->assertTrue((bool) $template->is_active);
            $this->assertNull($designs->layoutProblem($design->layout));
            $this->assertNull($designs->contentProblem($template->content, $layout));
            $this->assertSame($designs->plainTextBody($layout, $content), $template->body);
            $this->assertSame(1, DB::table('crm_email_template_options')->where('org_id', $org)->where('type', 'purpose')->where('value', $name)->whereNull('deleted_at')->count());
        }

        $this->assertStringContainsString('₱50,000', (string) DB::table('crm_email_templates')->where('purpose', 'GCamp Proposal')->value('body'));
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->migrate();
        $before = [$this->counts(), DB::table('crm_email_designs')->orderBy('id')->get(['id', 'name', 'layout'])->all(), DB::table('crm_email_templates')->orderBy('id')->get(['id', 'purpose', 'subject', 'body', 'content', 'email_design_id', 'is_active'])->all()];

        $this->migrate();
        $this->migrate();

        $this->assertEquals($before, [$this->counts(), DB::table('crm_email_designs')->orderBy('id')->get(['id', 'name', 'layout'])->all(), DB::table('crm_email_templates')->orderBy('id')->get(['id', 'purpose', 'subject', 'body', 'content', 'email_design_id', 'is_active'])->all()]);
    }

    public function test_existing_live_records_are_updated_in_place_whatever_their_ids(): void
    {
        $org = $this->providerId();
        $now = now();
        // Ids nothing like the local ones, an outdated layout, and an inactive template pointing at another design.
        DB::table('crm_email_designs')->insert(['id' => 500, 'org_id' => $org, 'name' => 'Other', 'is_default' => false, 'layout' => '{}', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('crm_email_designs')->insert(['id' => 700, 'org_id' => $org, 'name' => 'GCamp Proposal', 'is_default' => false, 'layout' => '{"old":true}', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('crm_email_templates')->insert(['id' => 900, 'org_id' => $org, 'purpose' => 'GCamp Proposal', 'tone' => 'Warm & professional', 'subject' => 'Old', 'body' => 'Old', 'content' => null, 'email_design_id' => 500, 'is_active' => false, 'created_at' => $now, 'updated_at' => $now]);

        $this->migrate();

        $this->assertSame(1, DB::table('crm_email_designs')->where('name', 'GCamp Proposal')->count());
        $this->assertNotSame('{"old":true}', DB::table('crm_email_designs')->where('id', 700)->value('layout'));
        $template = DB::table('crm_email_templates')->where('purpose', 'GCamp Proposal')->sole();
        $this->assertSame(900, $template->id);
        $this->assertSame(700, $template->email_design_id);
        $this->assertTrue((bool) $template->is_active);
        $this->assertSame('GCamp streaming app proposal and NFC card quote', $template->subject);
        $this->assertSame('{}', DB::table('crm_email_designs')->where('id', 500)->value('layout'), 'An unrelated design is untouched.');
    }

    public function test_a_deleted_purpose_is_restored_and_deleted_designs_and_templates_stay_deleted(): void
    {
        $org = $this->providerId();
        $now = now();
        DB::table('crm_email_template_options')->insert(['id' => 300, 'org_id' => $org, 'type' => 'purpose', 'value' => 'Revora quotation', 'normalized_value' => 'revora quotation', 'created_at' => $now, 'updated_at' => $now, 'deleted_at' => $now]);
        DB::table('crm_email_designs')->insert(['id' => 301, 'org_id' => $org, 'name' => 'Revora Quotation', 'is_default' => false, 'layout' => '{}', 'created_at' => $now, 'updated_at' => $now, 'deleted_at' => $now]);
        DB::table('crm_email_templates')->insert(['id' => 302, 'org_id' => $org, 'purpose' => 'Revora Quotation', 'tone' => 'Warm & professional', 'subject' => 'Old', 'body' => 'Old', 'email_design_id' => 301, 'is_active' => false, 'created_at' => $now, 'updated_at' => $now, 'deleted_at' => $now]);

        $this->migrate();

        $this->assertNull(DB::table('crm_email_template_options')->where('id', 300)->value('deleted_at'));
        $this->assertSame(1, DB::table('crm_email_template_options')->where('normalized_value', 'revora quotation')->count());
        $this->assertNotNull(DB::table('crm_email_designs')->where('id', 301)->value('deleted_at'));
        $this->assertNotNull(DB::table('crm_email_templates')->where('id', 302)->value('deleted_at'));
        $live = DB::table('crm_email_templates')->where('purpose', 'Revora Quotation')->whereNull('deleted_at')->sole();
        $this->assertNotSame(301, $live->email_design_id);
    }

    public function test_other_orgs_are_never_touched(): void
    {
        $other = Org::create(['name' => 'Another client', 'is_active' => true]);
        $now = now();
        DB::table('crm_email_designs')->insert(['org_id' => $other->id, 'name' => 'GCamp Proposal', 'is_default' => false, 'layout' => '{"theirs":true}', 'created_at' => $now, 'updated_at' => $now]);

        $this->migrate();

        $this->assertSame('{"theirs":true}', DB::table('crm_email_designs')->where('org_id', $other->id)->value('layout'));
        $this->assertSame(0, DB::table('crm_email_templates')->where('org_id', $other->id)->count());
        $this->assertSame(0, DB::table('crm_email_template_options')->where('org_id', $other->id)->count());
    }
}
