<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\Note;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The accepted-header contract for lead import.
 *
 * Business Name is the ONLY required column; everything else — contact information included — is
 * optional (PO decision, 2026-08-14). Before that, a sheet without a "Contact Name" column was
 * rejected outright even though the contact VALUE had been optional since 2026-07-14, so a
 * company-only prospecting list could not be imported at all.
 *
 * Also covers Comment → Lead Note: a remarks column becomes the lead's first internal note through
 * LeadNoteCreator (the same path the Create Lead wizard uses), never a column on crm_leads.
 */
class LeadImportContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function bd(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('crm_bd');

        return $user;
    }

    /**
     * CSV, not XLSX, on purpose. Everything asserted here — header aliasing, the required/optional
     * contract, contact defaulting, note mapping — happens AFTER readRows() has flattened the upload
     * to rows of strings, so it is identical for both formats; LeadImportXlsTest already pins
     * xls/xlsx parity. Building these through PhpSpreadsheet's zip writer instead exhausted the
     * 128 MB worker when the full CRM suite ran.
     *
     * @param  list<list<string>>  $rows
     */
    private function sheet(array $rows, string $name = 'leads.csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'test-csv-').'.csv';
        $handle = fopen($path, 'w');
        foreach ($rows as $cells) {
            fputcsv($handle, $cells);
        }
        fclose($handle);

        return new UploadedFile($path, $name, null, null, true);
    }

    /** @param  list<list<string>>  $rows */
    private function commit(User $as, array $rows): TestResponse
    {
        Sanctum::actingAs($as);

        return $this->post('/api/v1/crm/leads/import', [
            'file' => $this->sheet($rows),
            'dry_run' => 'false',
        ]);
    }

    // ───────────────────────────────────────────── required / optional columns

    public function test_a_sheet_with_only_business_name_imports(): void
    {
        $this->commit($this->bd(), [
            ['Business Name'],
            ['Acme Dental'],
        ])->assertOk()->assertJsonPath('data.summary.valid', 1);

        $lead = Lead::firstWhere('business_name', 'Acme Dental');
        $this->assertNotNull($lead, 'a Business-Name-only sheet must import');
        // NOT NULL with no default + STRICT_TRANS_TABLES: the absent column must land as ''.
        $this->assertSame('', $lead->contact_name);
    }

    public function test_a_missing_contact_column_does_not_500_on_commit(): void
    {
        // The regression this contract change could most easily introduce: dropping the header gate
        // without defaulting contact_name turns a clean 422 into
        // "Field 'contact_name' doesn't have a default value" at insert time.
        $response = $this->commit($this->bd(), [
            ['Business Name', 'Email', 'Niche'],
            ['No Contact Co', 'hello@nocontact.ph', 'Retail'],
        ]);

        $response->assertOk();
        $this->assertSame(200, $response->getStatusCode(), 'commit must not fault on a contactless sheet');
        $this->assertSame('', Lead::firstWhere('business_name', 'No Contact Co')->contact_name);
    }

    public function test_contact_name_column_is_mapped_when_present(): void
    {
        $this->commit($this->bd(), [
            ['Business Name', 'Contact Name'],
            ['Bright Future', 'Jose Cruz'],
        ])->assertOk();

        $this->assertSame('Jose Cruz', Lead::firstWhere('business_name', 'Bright Future')->contact_name);
    }

    public function test_owners_name_alias_is_mapped_to_contact_name(): void
    {
        // The apostrophe is stripped by normalizeHeader(), so "Owner's Name" == "owners name".
        $this->commit($this->bd(), [
            ['Business Name', "Owner's Name"],
            ['Castillo Accounting', 'Maria Santos'],
        ])->assertOk();

        $this->assertSame('Maria Santos', Lead::firstWhere('business_name', 'Castillo Accounting')->contact_name);
    }

    public function test_other_optional_columns_still_map_without_a_contact_column(): void
    {
        $this->commit($this->bd(), [
            ['Business Name', 'Niche', 'Address', 'Contact Number', 'Email'],
            ['Unstable Records', 'Record Label', '12 Salcedo St', '(02) 8284-8789', 'hi@unstable.ph'],
        ])->assertOk();

        $lead = Lead::firstWhere('business_name', 'Unstable Records');
        $this->assertSame('Record Label', $lead->industry);
        $this->assertSame('12 Salcedo St', $lead->address);
        $this->assertSame('(02) 8284-8789', $lead->phone);
        $this->assertSame('hi@unstable.ph', $lead->email);
        $this->assertSame('', $lead->contact_name);
    }

    public function test_a_sheet_without_business_name_is_still_rejected(): void
    {
        // The one remaining hard requirement — relaxing contact must not relax this too.
        Sanctum::actingAs($this->bd());

        $this->post('/api/v1/crm/leads/import', [
            'file' => $this->sheet([
                ['Contact Name', 'Email'],
                ['Jose Cruz', 'jose@bright.ph'],
            ]),
            'dry_run' => 'false',
        ])->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertSame(0, Lead::count());
    }

    // ───────────────────────────────────────────────────────── Comment → Note

    public function test_a_comment_column_becomes_the_leads_first_note(): void
    {
        $bd = $this->bd();

        $this->commit($bd, [
            ['Business Name', 'Comment'],
            ['Acme Dental', 'Consultation scheduled June 25, 1:30pm via Zoom.'],
        ])->assertOk();

        $lead = Lead::firstWhere('business_name', 'Acme Dental');
        $note = Note::where('lead_id', $lead->id)->first();

        $this->assertNotNull($note, 'the Comment column must create a crm_notes row');
        $this->assertSame('Consultation scheduled June 25, 1:30pm via Zoom.', $note->body);
        // Authored by the importer, through the same service the wizard uses.
        $this->assertSame($bd->id, $note->user_id);
    }

    public function test_comment_is_mapped_in_the_preview_as_lead_note(): void
    {
        Sanctum::actingAs($this->bd());

        $this->post('/api/v1/crm/leads/import', [
            'file' => $this->sheet([
                ['Business Name', 'Comment'],
                ['Acme Dental', 'Called twice.'],
            ]),
            'dry_run' => 'true',
        ])->assertOk()->assertJsonFragment(['csvColumn' => 'Comment', 'crmField' => 'Lead Note']);
    }

    /**
     * Every approved alias resolves to the same field, so a sheet titled "Remarks" behaves
     * identically to one titled "Comment".
     */
    public function test_every_note_alias_creates_a_note(): void
    {
        foreach (['Comments', 'Note', 'Notes', 'Remarks'] as $index => $header) {
            $business = "Alias Co {$index}";

            $this->commit($this->bd(), [
                ['Business Name', $header],
                [$business, "body via {$header}"],
            ])->assertOk();

            $lead = Lead::firstWhere('business_name', $business);
            $this->assertSame(
                "body via {$header}",
                Note::where('lead_id', $lead->id)->value('body'),
                "the \"{$header}\" header must map to Lead Note",
            );
        }
    }

    public function test_a_row_without_a_comment_creates_no_note(): void
    {
        $this->commit($this->bd(), [
            ['Business Name', 'Comment'],
            ['Quiet Co', ''],
        ])->assertOk();

        $this->assertSame(0, Note::count(), 'a blank comment must not create an empty note');
    }

    public function test_an_over_long_comment_is_truncated_not_rejected(): void
    {
        // Approved behaviour: the column was ignored entirely before this mapping existed, so a long
        // comment must never cost the row its import.
        $body = str_repeat('x', 6000);

        $this->commit($this->bd(), [
            ['Business Name', 'Comment'],
            ['Verbose Co', $body],
        ])->assertOk()->assertJsonPath('data.summary.errors', 0);

        $lead = Lead::firstWhere('business_name', 'Verbose Co');
        $this->assertNotNull($lead, 'an over-long comment must not fail the row');
        $this->assertSame(5000, mb_strlen(Note::where('lead_id', $lead->id)->value('body')));
    }

    public function test_restoring_a_soft_deleted_lead_creates_no_note(): void
    {
        $bd = $this->bd();
        // A previously archived lead that the sheet matches on Tier-1 (email).
        $lead = Lead::factory()->create([
            'business_name' => 'Archived Co',
            'email' => 'hello@archived.ph',
        ]);
        $lead->delete();

        $this->commit($bd, [
            ['Business Name', 'Email', 'Comment'],
            ['Archived Co', 'hello@archived.ph', 'should not become a note'],
        ])->assertOk()->assertJsonPath('data.summary.restored', 1);

        $this->assertNull($lead->fresh()->deleted_at, 'the matched lead is restored, not duplicated');
        $this->assertSame(0, Note::where('lead_id', $lead->id)->count(),
            'a restore must not append a note — Comment → Note applies to new leads only');
    }
}
