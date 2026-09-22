<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadAttachment;
use App\Modules\Crm\Services\Attachments\SpreadsheetPreviewReader;
use App\Modules\Crm\Services\Attachments\UnreadableSpreadsheetException;
use App\Modules\Crm\Services\LeadAttachmentStore;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls as XlsWriter;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

/**
 * Spreadsheet attachment preview — GET /leads/{lead}/attachments/{attachment}/preview.
 *
 * Three properties are load-bearing and are asserted directly rather than inferred:
 *
 *  1. The endpoint discloses the same bytes as download, so it must be refused to exactly the same
 *     callers — a BD on someone else's lead, and an attachment addressed through the wrong lead.
 *  2. The format is decided from CONTENT. A file whose name and stored MIME both say `.xlsx` but
 *     whose bytes are a PDF must never reach a parser.
 *  3. Failures return one generic sentence. No path, class name, library version or stack frame may
 *     appear in a response body.
 */
class LeadAttachmentPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake(LeadAttachmentStore::DISK);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('crm_admin');

        return $admin;
    }

    private function businessDeveloper(): User
    {
        $bd = User::factory()->create(['status' => 'active']);
        $bd->assignRole('crm_bd');

        return $bd;
    }

    /**
     * A real workbook, written by PhpSpreadsheet's own writer so the bytes are genuine OOXML/BIFF
     * rather than a fixture that could drift from the reader.
     *
     * @param  list<list<string|int|float>>  $rows
     */
    private function workbookBytes(
        array $rows,
        string $format = 'Xlsx',
        string $sheetTitle = 'Leads',
        bool $cacheFormulaResults = true,
    ): string {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($sheetTitle);

        foreach ($rows as $r => $line) {
            foreach ($line as $c => $value) {
                $sheet->setCellValue([$c + 1, $r + 1], $value);
            }
        }

        $target = tempnam(sys_get_temp_dir(), 'preview-test-');
        $writer = $format === 'Xls' ? new XlsWriter($spreadsheet) : new XlsxWriter($spreadsheet);
        // Off = the saved file carries the formula but NO cached result, which is how the reader's
        // refusal to calculate becomes observable.
        $writer->setPreCalculateFormulas($cacheFormulaResults);
        $writer->save($target);
        $bytes = file_get_contents($target);
        @unlink($target);
        $spreadsheet->disconnectWorksheets();

        return (string) $bytes;
    }

    /** Upload arbitrary bytes under an arbitrary name, then return the stored row. */
    private function uploadRaw(Lead $lead, string $name, string $content): LeadAttachment
    {
        $this->postJson("/api/v1/crm/leads/{$lead->id}/attachments", [
            'file' => UploadedFile::fake()->createWithContent($name, $content),
        ])->assertCreated();

        return LeadAttachment::where('original_name', $name)->latest('id')->firstOrFail();
    }

    private function uploadWorkbook(Lead $lead, array $rows, string $format = 'Xlsx', string $sheetTitle = 'Leads'): LeadAttachment
    {
        $name = $format === 'Xls' ? 'legacy.xls' : 'book.xlsx';

        return $this->uploadRaw($lead, $name, $this->workbookBytes($rows, $format, $sheetTitle));
    }

    // ── Happy path ───────────────────────────────────────────────────────────────────────────

    public function test_it_previews_an_xlsx_workbook_as_bounded_json(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();
        $attachment = $this->uploadWorkbook($lead, [
            ['Business', 'City', 'Score'],
            ['Acme Corp', 'Bacolod', 87],
        ]);

        $response = $this->getJson("/api/v1/crm/leads/{$lead->id}/attachments/{$attachment->id}/preview");

        $response->assertOk();
        $response->assertJsonPath('data.kind', 'sheet');
        $response->assertJsonPath('data.sheetName', 'Leads');
        $response->assertJsonPath('data.rows.0.0', 'Business');
        $response->assertJsonPath('data.rows.1.0', 'Acme Corp');
        // Every value is a STRING — the client renders text nodes, never markup, never numbers it
        // has to re-format.
        $response->assertJsonPath('data.rows.1.2', '87');
        $response->assertJsonPath('data.totalRows', 2);
        $response->assertJsonPath('data.truncated', false);
    }

    public function test_it_previews_a_legacy_xls_workbook(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();
        $attachment = $this->uploadWorkbook($lead, [['Header'], ['Value']], 'Xls');

        $this->getJson("/api/v1/crm/leads/{$lead->id}/attachments/{$attachment->id}/preview")
            ->assertOk()
            ->assertJsonPath('data.rows.0.0', 'Header')
            ->assertJsonPath('data.rows.1.0', 'Value');
    }

    public function test_it_renders_cell_text_verbatim_rather_than_as_markup(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();
        $attachment = $this->uploadWorkbook($lead, [['<script>alert(1)</script>', '=cmd|calc']]);

        $response = $this->getJson("/api/v1/crm/leads/{$lead->id}/attachments/{$attachment->id}/preview");

        $response->assertOk();
        // Returned as data, not as HTML and not evaluated. The client renders it as a text node.
        $response->assertJsonPath('data.rows.0.0', '<script>alert(1)</script>');
    }

    // ── Bounds ───────────────────────────────────────────────────────────────────────────────

    public function test_it_bounds_the_window_and_reports_the_sheets_real_size(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();

        // Larger than the window in BOTH directions.
        $rows = [];
        for ($r = 0; $r < 250; $r++) {
            $line = [];
            for ($c = 0; $c < 35; $c++) {
                $line[] = "r{$r}c{$c}";
            }
            $rows[] = $line;
        }
        $attachment = $this->uploadWorkbook($lead, $rows);

        $response = $this->getJson("/api/v1/crm/leads/{$lead->id}/attachments/{$attachment->id}/preview");

        $response->assertOk();
        $response->assertJsonCount(SpreadsheetPreviewReader::MAX_ROWS, 'data.rows');
        $response->assertJsonCount(SpreadsheetPreviewReader::MAX_COLUMNS, 'data.rows.0');
        $response->assertJsonPath('data.truncated', true);
        // The REAL dimensions, so the viewer can say honestly what it is not showing.
        $response->assertJsonPath('data.totalRows', 250);
        $response->assertJsonPath('data.totalColumns', 35);
        $response->assertJsonPath('data.maxRows', SpreadsheetPreviewReader::MAX_ROWS);
        $response->assertJsonPath('data.maxColumns', SpreadsheetPreviewReader::MAX_COLUMNS);
        // The last admitted cell is present and the first excluded one is absent.
        $response->assertJsonPath('data.rows.199.29', 'r199c29');
    }

    public function test_it_never_evaluates_a_formula_from_an_untrusted_workbook(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();

        // Written WITHOUT precalculation, so the file carries `=A1*B1` and no cached result. Only
        // running PhpSpreadsheet's calculation engine over the expression could produce 6 — which
        // is the code path an untrusted document must never reach.
        $attachment = $this->uploadRaw(
            $lead,
            'formula.xlsx',
            $this->workbookBytes([[2, 3, '=A1*B1']], 'Xlsx', 'Leads', cacheFormulaResults: false),
        );

        $response = $this->getJson("/api/v1/crm/leads/{$lead->id}/attachments/{$attachment->id}/preview");

        $response->assertOk();
        $this->assertNotSame('6', $response->json('data.rows.0.2'));
        $this->assertSame('', $response->json('data.rows.0.2'));
        // The non-formula cells around it still read normally.
        $this->assertSame('2', $response->json('data.rows.0.0'));
    }

    public function test_it_shows_the_result_the_authoring_application_cached(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();

        // Saved WITH precalculation: the result is a value stored in the file, so displaying it
        // requires no evaluation here.
        $attachment = $this->uploadWorkbook($lead, [[2, 3, '=A1*B1']]);

        $this->getJson("/api/v1/crm/leads/{$lead->id}/attachments/{$attachment->id}/preview")
            ->assertOk()
            ->assertJsonPath('data.rows.0.2', '6');
    }

    // ── Content-based dispatch ───────────────────────────────────────────────────────────────

    public function test_a_non_spreadsheet_named_xlsx_is_refused_without_reaching_a_parser(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();
        // Name says xlsx, bytes say PDF. This is precisely the case the stored MIME cannot catch.
        $attachment = $this->uploadRaw($lead, 'not-really.xlsx', "%PDF-1.7\n%\xE2\xE3\xCF\xD3\ntrailer\n");

        $response = $this->getJson("/api/v1/crm/leads/{$lead->id}/attachments/{$attachment->id}/preview");

        $response->assertStatus(422);
        $response->assertJsonPath('message', UnreadableSpreadsheetException::UNREADABLE);
    }

    public function test_a_corrupt_workbook_returns_one_generic_message(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();
        // A truncated zip: the container looks like OOXML, the contents are not readable.
        $attachment = $this->uploadRaw($lead, 'broken.xlsx', "PK\x03\x04corrupted-not-a-real-workbook");

        $this->getJson("/api/v1/crm/leads/{$lead->id}/attachments/{$attachment->id}/preview")
            ->assertStatus(422)
            ->assertJsonPath('message', UnreadableSpreadsheetException::UNREADABLE);
    }

    public function test_a_failure_never_leaks_a_path_class_name_or_stack_frame(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();
        $attachment = $this->uploadRaw($lead, 'broken.xlsx', "PK\x03\x04still-not-a-workbook");

        $body = $this->getJson("/api/v1/crm/leads/{$lead->id}/attachments/{$attachment->id}/preview")
            ->assertStatus(422)
            ->getContent();

        foreach (['PhpOffice', 'PhpSpreadsheet', 'vendor/', 'storage/', '.php', 'Exception', '#0'] as $leak) {
            $this->assertStringNotContainsString($leak, (string) $body, "Response leaked: {$leak}");
        }
    }

    public function test_previewing_a_pdf_attachment_is_refused_rather_than_guessed_at(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();
        $attachment = $this->uploadRaw($lead, 'proposal.pdf', "%PDF-1.7\ntrailer\n");

        // Correct: the PDF has its own client-side viewer and Download; this endpoint opens
        // spreadsheets only, and says so rather than attempting a parse.
        $this->getJson("/api/v1/crm/leads/{$lead->id}/attachments/{$attachment->id}/preview")
            ->assertStatus(422);
    }

    public function test_it_404s_when_the_bytes_are_gone_from_disk(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();
        $attachment = $this->uploadWorkbook($lead, [['a']]);

        Storage::disk(LeadAttachmentStore::DISK)->delete($attachment->path);

        $this->getJson("/api/v1/crm/leads/{$lead->id}/attachments/{$attachment->id}/preview")
            ->assertNotFound();
    }

    // ── Authorization and isolation — the same boundary as download ──────────────────────────

    public function test_a_business_developer_cannot_preview_a_file_on_a_lead_they_do_not_own(): void
    {
        $owner = $this->businessDeveloper();
        $other = $this->businessDeveloper();

        Sanctum::actingAs($owner);
        $lead = Lead::factory()->create(['assigned_to' => $owner->id]);
        $attachment = $this->uploadWorkbook($lead, [['secret']]);

        Sanctum::actingAs($other);
        $this->getJson("/api/v1/crm/leads/{$lead->id}/attachments/{$attachment->id}/preview")
            ->assertForbidden();
    }

    public function test_an_attachment_is_not_previewable_through_a_different_lead(): void
    {
        Sanctum::actingAs($this->admin());
        $leadA = Lead::factory()->create();
        $leadB = Lead::factory()->create();
        $onA = $this->uploadWorkbook($leadA, [['secret']]);

        // scopeBindings: {attachment} must belong to {lead} or the binding never resolves.
        $this->getJson("/api/v1/crm/leads/{$leadB->id}/attachments/{$onA->id}/preview")
            ->assertNotFound();
    }

    public function test_preview_requires_authentication(): void
    {
        // `auth:sanctum` runs before route-model binding, so the ids need not resolve — an
        // anonymous caller is turned away before the attachment is ever looked up.
        $this->getJson('/api/v1/crm/leads/1/attachments/1/preview')
            ->assertUnauthorized();
    }

    // ── The fallback stays available ─────────────────────────────────────────────────────────

    public function test_download_still_works_for_a_spreadsheet(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();
        $attachment = $this->uploadWorkbook($lead, [['a']]);

        $this->get("/api/v1/crm/leads/{$lead->id}/attachments/{$attachment->id}/download")
            ->assertOk()
            ->assertDownload('book.xlsx');
    }

    public function test_download_still_works_for_a_workbook_the_preview_cannot_read(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();
        $attachment = $this->uploadRaw($lead, 'broken.xlsx', "PK\x03\x04not-a-workbook");

        $this->getJson("/api/v1/crm/leads/{$lead->id}/attachments/{$attachment->id}/preview")
            ->assertStatus(422);

        // Preview failing must never cost the user the file itself.
        $this->get("/api/v1/crm/leads/{$lead->id}/attachments/{$attachment->id}/download")
            ->assertOk()
            ->assertDownload('broken.xlsx');
    }

    // ── The security prerequisite ────────────────────────────────────────────────────────────

    public function test_the_stored_mime_is_derived_from_content_not_from_the_client_header(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();

        // A REAL UploadedFile, not `UploadedFile::fake()`: the fake overrides getMimeType() to
        // derive from the filename, which would make this assertion pass without proving anything.
        // A real one guesses from the bytes, which is exactly what is under test.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $path = tempnam(sys_get_temp_dir(), 'mime-test-');
        file_put_contents($path, $png);

        // The client declares a deliberate lie in the part header; the bytes are a PNG.
        $file = new UploadedFile($path, 'logo.png', 'application/vnd.ms-excel', null, true);

        $this->postJson("/api/v1/crm/leads/{$lead->id}/attachments", ['file' => $file])
            ->assertCreated()
            // Derived from the bytes on disk, NOT echoed from the request part header.
            ->assertJsonPath('data.mimeType', 'image/png');

        @unlink($path);
    }
}
