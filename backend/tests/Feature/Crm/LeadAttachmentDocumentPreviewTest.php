<?php

namespace Tests\Feature\Crm;

use App\Models\Org;
use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadAttachment;
use App\Modules\Crm\Services\Attachments\Contracts\OfficeToPdfConverter;
use App\Modules\Crm\Services\Attachments\DocumentPreviewException;
use App\Modules\Crm\Services\Attachments\DocumentPreviewService;
use App\Modules\Crm\Services\Attachments\GotenbergConverter;
use App\Modules\Crm\Services\Attachments\OfficeFormat;
use App\Modules\Crm\Services\LeadAttachmentStore;
use App\Services\OrgOnboardingService;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * Office document preview — GET /leads/{lead}/attachments/{attachment}/document-preview.
 *
 * What is asserted directly rather than inferred:
 *
 *  1. The four formats convert end to end — through the REAL GotenbergConverter, against a faked
 *     Gotenberg HTTP API, so the whole path from route to cached PDF runs on any host.
 *  2. Admission is by CONTENT and by extension together: a file whose bytes are not the Office format
 *     its name claims never reaches the converter, and neither does anything outside the four.
 *  3. The endpoint is refused to exactly the callers download refuses — including across tenants.
 *  4. A conversion happens once: the PDF is cached, a failure is remembered, and a busy converter
 *     says so rather than queueing.
 *  5. Deleting the attachment deletes every cached PDF of it.
 *
 * Everything except (1) and the Gotenberg-answer tests runs against a counting fake converter, so
 * those tests prove the service never even ASKED to convert what it should have refused.
 */
class LeadAttachmentDocumentPreviewTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURES = __DIR__.'/../../Fixtures/office';

    private const TINY_PDF = "%PDF-1.4\n1 0 obj <<>> endobj\ntrailer <<>>\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake(LeadAttachmentStore::DISK);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────────────────

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

    private function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES.'/'.$name);
    }

    private function uploadRaw(Lead $lead, string $name, string $content): LeadAttachment
    {
        $this->postJson("/api/v1/crm/leads/{$lead->id}/attachments", [
            'file' => UploadedFile::fake()->createWithContent($name, $content),
        ])->assertCreated();

        return LeadAttachment::where('original_name', $name)->latest('id')->firstOrFail();
    }

    private function preview(Lead $lead, LeadAttachment $attachment): TestResponse
    {
        return $this->get("/api/v1/crm/leads/{$lead->id}/attachments/{$attachment->id}/document-preview", [
            'Accept' => 'application/json',
        ]);
    }

    /**
     * A converter that counts its calls and writes a tiny valid PDF — or throws, when told to.
     */
    private function fakeConverter(bool $available = true, ?\Throwable $throws = null): object
    {
        $fake = new class($available, $throws) implements OfficeToPdfConverter
        {
            public int $calls = 0;

            public function __construct(private bool $available, private ?\Throwable $throws) {}

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function convert(string $sourcePath, OfficeFormat $format, string $targetPath): void
            {
                $this->calls++;
                if ($this->throws !== null) {
                    throw $this->throws;
                }
                file_put_contents($targetPath, "%PDF-1.4\n1 0 obj <<>> endobj\ntrailer <<>>\n%%EOF\n");
            }
        };

        $this->app->instance(OfficeToPdfConverter::class, $fake);

        return $fake;
    }

    private const GOTENBERG_URL = 'http://gotenberg.test:3000';

    /**
     * The REAL converter, configured, with Gotenberg's HTTP API faked. Any request the fake does not
     * answer fails the test, so nothing here can reach a real network.
     */
    private function fakeGotenberg(): void
    {
        config([
            'office_preview.enabled' => true,
            'office_preview.gotenberg.url' => self::GOTENBERG_URL,
            'office_preview.gotenberg.username' => null,
            'office_preview.gotenberg.password' => null,
        ]);
        Http::preventStrayRequests();
    }

    /** @return list<string> cached preview files for one attachment */
    private function cachedPreviews(LeadAttachment $attachment): array
    {
        $disk = Storage::disk(LeadAttachmentStore::DISK);

        return array_values(array_filter(
            $disk->files(DocumentPreviewService::CACHE_DIRECTORY.'/'.$attachment->lead_id),
            fn (string $f): bool => str_starts_with(basename($f), $attachment->id.'-'),
        ));
    }

    /** An OOXML package with exactly the given content types, for the admission tests. */
    private function ooxml(string $mainType, array $extraEntries = [], int $padEntries = 0): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ooxml-test-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Override PartName="/word/document.xml" ContentType="'.$mainType.'"/></Types>');
        $zip->addFromString('word/document.xml', '<w:document/>');
        foreach ($extraEntries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        for ($i = 0; $i < $padEntries; $i++) {
            $zip->addFromString("pad/{$i}.xml", 'x');
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    // ── 1. All four formats, end to end through the Gotenberg converter ─────────────────────

    /** @return array<string, array{string}> */
    public static function officeFixtures(): array
    {
        return [
            'doc' => ['proposal.doc'],
            'docx' => ['proposal.docx'],
            'ppt' => ['deck.ppt'],
            'pptx' => ['deck.pptx'],
        ];
    }

    #[DataProvider('officeFixtures')]
    public function test_an_office_document_is_converted_by_gotenberg_and_served_as_an_inline_pdf(string $fixture): void
    {
        $this->fakeGotenberg();
        Http::fake([self::GOTENBERG_URL.GotenbergConverter::ROUTE => Http::response(self::TINY_PDF, 200, ['Content-Type' => 'application/pdf'])]);

        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();
        $attachment = $this->uploadRaw($lead, $fixture, $this->fixture($fixture));

        $response = $this->preview($lead, $attachment);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Content-Security-Policy', 'sandbox');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));

        $this->assertSame(self::TINY_PDF, $response->streamedContent(), 'The PDF Gotenberg returned, byte for byte.');
        $this->assertCount(1, $this->cachedPreviews($attachment), 'The PDF must be cached on the private disk.');

        // The stored bytes went to Gotenberg under a fixed name — never the uploader's filename.
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->hasFile(
            'files',
            $this->fixture($fixture),
            'document.'.pathinfo($fixture, PATHINFO_EXTENSION),
        ));

        // The second view is a cache read: Gotenberg is not asked again.
        $this->preview($lead, $attachment)->assertOk();
        Http::assertSentCount(1);
    }

    public function test_a_document_gotenberg_rejects_gets_the_generic_message_and_is_not_resent_on_every_click(): void
    {
        $this->fakeGotenberg();
        Http::fake([self::GOTENBERG_URL.GotenbergConverter::ROUTE => Http::response(
            'LibreOffice failed to process a document: /tmp/gotenberg/7f3a/document.docx',
            400,
            ['Content-Type' => 'text/plain'],
        )]);
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();
        $attachment = $this->uploadRaw($lead, 'proposal.docx', $this->fixture('proposal.docx'));

        $body = (string) $this->preview($lead, $attachment)
            ->assertStatus(422)
            ->assertJsonPath('message', DocumentPreviewException::UNREADABLE)
            ->getContent();

        foreach (['Gotenberg', 'LibreOffice', '/tmp', 'gotenberg.test', 'Exception'] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "Response leaked: {$leak}");
        }

        $this->preview($lead, $attachment)->assertStatus(422);
        Http::assertSentCount(1);
        $this->assertSame([], $this->cachedPreviews($attachment));
    }

    public function test_an_unreachable_gotenberg_answers_503_and_is_not_held_against_the_document(): void
    {
        $this->fakeGotenberg();
        Http::fakeSequence(self::GOTENBERG_URL.GotenbergConverter::ROUTE)
            ->pushFailedConnection('cURL error 7: Failed to connect to gotenberg.test port 3000')
            ->push(self::TINY_PDF, 200, ['Content-Type' => 'application/pdf']);
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();
        $attachment = $this->uploadRaw($lead, 'deck.pptx', $this->fixture('deck.pptx'));

        $this->preview($lead, $attachment)
            ->assertStatus(503)
            ->assertJsonPath('message', DocumentPreviewException::UNAVAILABLE);
        $this->assertSame([], $this->cachedPreviews($attachment));

        // The service came back: the same document converts at once, because an outage is not
        // remembered as the document being broken.
        $this->preview($lead, $attachment)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertCount(1, $this->cachedPreviews($attachment));
    }

    // ── 2. Admission: allowlist, and content must match the name ────────────────────────────

    public function test_formats_outside_the_allowlist_are_refused_before_conversion(): void
    {
        $fake = $this->fakeConverter();
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();

        $files = [
            'proposal.pdf' => self::TINY_PDF,
            'bundle.zip' => "PK\x03\x04zip-bytes",
            'book.xlsx' => $this->ooxml('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml'),
            'notes.txt' => 'plain text',
            'data.csv' => "a,b\n1,2\n",
        ];

        foreach ($files as $name => $content) {
            $attachment = $this->uploadRaw($lead, $name, $content);

            $this->preview($lead, $attachment)
                ->assertStatus(422)
                ->assertJsonPath('message', DocumentPreviewException::UNSUPPORTED);
        }

        $this->assertSame(0, $fake->calls, 'Nothing outside the four formats may reach the converter.');
    }

    public function test_a_file_whose_bytes_are_not_what_its_name_claims_is_refused(): void
    {
        $fake = $this->fakeConverter();
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();

        $impostors = [
            'pdf-named.docx' => self::TINY_PDF,
            'docx-named.doc' => $this->fixture('proposal.docx'),       // OOXML where OLE is claimed
            'word-named.pptx' => $this->fixture('proposal.docx'),      // a Word package claiming PowerPoint
            'word-named.ppt' => $this->fixture('proposal.doc'),        // WordDocument stream, not PowerPoint
            'deck-named.doc' => $this->fixture('deck.ppt'),            // PowerPoint stream, not Word
            'html-named.docx' => '<html><img src="http://127.0.0.1/"></html>',
            'truncated.docx' => "PK\x03\x04not-a-real-archive",
        ];

        foreach ($impostors as $name => $content) {
            $attachment = $this->uploadRaw($lead, $name, $content);

            $this->preview($lead, $attachment)
                ->assertStatus(422)
                ->assertJsonPath('message', DocumentPreviewException::UNSUPPORTED);
        }

        $this->assertSame(0, $fake->calls);
    }

    public function test_macro_enabled_and_oversized_packages_are_refused(): void
    {
        $fake = $this->fakeConverter();
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();
        $docxMain = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml';

        $refused = [
            // A .docm renamed .docx: its main part type says macro-enabled.
            'renamed-docm.docx' => $this->ooxml('application/vnd.ms-word.document.macroEnabled.main+xml'),
            // Claims to be a plain docx but carries a VBA project anyway.
            'hidden-macro.docx' => $this->ooxml($docxMain, ['word/vbaProject.bin' => 'vba']),
            // Too many entries to be a real document: refused from the directory alone.
            'many-entries.docx' => $this->ooxml($docxMain, [], 2001),
        ];

        foreach ($refused as $name => $content) {
            $attachment = $this->uploadRaw($lead, $name, $content);

            $this->preview($lead, $attachment)->assertStatus(422);
        }

        $this->assertSame(0, $fake->calls);
    }

    // ── 3. Authorization and isolation — the same boundary as download ──────────────────────

    public function test_a_business_developer_cannot_preview_a_document_on_a_lead_they_do_not_own(): void
    {
        $fake = $this->fakeConverter();
        $owner = $this->businessDeveloper();
        $other = $this->businessDeveloper();

        Sanctum::actingAs($owner);
        $lead = Lead::factory()->create(['assigned_to' => $owner->id]);
        $attachment = $this->uploadRaw($lead, 'proposal.docx', $this->fixture('proposal.docx'));

        Sanctum::actingAs($other);
        $this->preview($lead, $attachment)->assertForbidden();

        $this->assertSame(0, $fake->calls);
    }

    public function test_the_owning_business_developer_can_preview_their_own_document(): void
    {
        $this->fakeConverter();
        $owner = $this->businessDeveloper();

        Sanctum::actingAs($owner);
        $lead = Lead::factory()->create(['assigned_to' => $owner->id]);
        $attachment = $this->uploadRaw($lead, 'proposal.docx', $this->fixture('proposal.docx'));

        $this->preview($lead, $attachment)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_an_attachment_is_not_previewable_through_a_different_lead(): void
    {
        $fake = $this->fakeConverter();
        Sanctum::actingAs($this->admin());
        $leadA = Lead::factory()->create();
        $leadB = Lead::factory()->create();
        $onA = $this->uploadRaw($leadA, 'proposal.docx', $this->fixture('proposal.docx'));

        $this->preview($leadB, $onA)->assertNotFound();
        $this->assertSame(0, $fake->calls);
    }

    public function test_preview_requires_authentication(): void
    {
        $this->getJson('/api/v1/crm/leads/1/attachments/1/document-preview')->assertUnauthorized();
    }

    public function test_another_tenants_document_cannot_be_previewed(): void
    {
        $fake = $this->fakeConverter();
        [, $theirAdmin] = $this->orgWithAdmin('theirs');
        [, $ourAdmin] = $this->orgWithAdmin('ours');

        $this->actAs($theirAdmin);
        $theirLead = Lead::factory()->create();
        $theirs = $this->uploadRaw($theirLead, 'their-proposal.docx', $this->fixture('proposal.docx'));

        $this->actAs($ourAdmin);
        $this->preview($theirLead, $theirs)->assertNotFound();

        $this->assertSame(0, $fake->calls, 'Another tenant\'s document must never be converted for us.');
        $this->assertSame([], $this->cachedPreviews($theirs));
    }

    // ── 4. Cache, failure memory, busy and unavailable ───────────────────────────────────────

    public function test_a_document_is_converted_once_and_then_served_from_the_private_cache(): void
    {
        $fake = $this->fakeConverter();
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();
        $attachment = $this->uploadRaw($lead, 'deck.pptx', $this->fixture('deck.pptx'));

        $this->preview($lead, $attachment)->assertOk();
        $this->preview($lead, $attachment)->assertOk();

        $this->assertSame(1, $fake->calls, 'The second view must be a cache read, not a conversion.');

        $cached = $this->cachedPreviews($attachment);
        $this->assertCount(1, $cached);
        // Keyed by the attachment AND the bytes it was made from.
        $this->assertStringEndsWith(
            "/{$attachment->id}-".hash('sha256', $this->fixture('deck.pptx')).'.pdf',
            $cached[0],
        );
    }

    public function test_a_conversion_failure_returns_one_generic_message_and_caches_nothing(): void
    {
        $fake = $this->fakeConverter(true, DocumentPreviewException::unreadable(
            new RuntimeException('Gotenberg answered 400: LibreOffice failed at /tmp/gotenberg/document.doc (/app/storage)'),
        ));
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();
        $attachment = $this->uploadRaw($lead, 'proposal.doc', $this->fixture('proposal.doc'));

        $body = $this->preview($lead, $attachment)
            ->assertStatus(422)
            ->assertJsonPath('message', DocumentPreviewException::UNREADABLE)
            ->getContent();

        foreach (['Gotenberg', 'LibreOffice', '/tmp', '/app/storage', '.php', 'Exception', '#0'] as $leak) {
            $this->assertStringNotContainsString($leak, (string) $body, "Response leaked: {$leak}");
        }

        $this->assertSame([], $this->cachedPreviews($attachment), 'A failed conversion must leave no PDF behind.');
    }

    public function test_a_failed_document_is_not_reconverted_on_every_click(): void
    {
        $fake = $this->fakeConverter(true, DocumentPreviewException::unreadable());
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();
        $attachment = $this->uploadRaw($lead, 'proposal.docx', $this->fixture('proposal.docx'));

        $this->preview($lead, $attachment)->assertStatus(422);
        $this->preview($lead, $attachment)->assertStatus(422);
        $this->assertSame(1, $fake->calls, 'The failure must be remembered.');

        // ...but only for a while: once it expires the document is tried again.
        Cache::flush();
        $this->preview($lead, $attachment)->assertStatus(422);
        $this->assertSame(2, $fake->calls);
    }

    public function test_a_busy_converter_answers_503_instead_of_queueing(): void
    {
        $fake = $this->fakeConverter();
        config(['office_preview.lock_wait_seconds' => 0]);
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();
        $attachment = $this->uploadRaw($lead, 'proposal.docx', $this->fixture('proposal.docx'));

        $held = Cache::lock('crm:office-preview:convert', 30);
        $this->assertTrue($held->get(), 'Precondition: the test holds the conversion lock.');

        try {
            $this->preview($lead, $attachment)
                ->assertStatus(503)
                ->assertJsonPath('message', DocumentPreviewException::BUSY);
        } finally {
            $held->release();
        }

        $this->assertSame(0, $fake->calls);
        $this->preview($lead, $attachment)->assertOk();
    }

    public function test_a_server_without_a_converter_answers_503_and_offers_no_preview(): void
    {
        $fake = $this->fakeConverter(available: false);
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();
        $attachment = $this->uploadRaw($lead, 'proposal.docx', $this->fixture('proposal.docx'));

        $this->preview($lead, $attachment)
            ->assertStatus(503)
            ->assertJsonPath('message', DocumentPreviewException::UNAVAILABLE);

        $this->getJson("/api/v1/crm/leads/{$lead->id}/attachments")
            ->assertOk()
            ->assertJsonPath('data.0.documentPreview', false);

        $this->assertSame(0, $fake->calls);
    }

    public function test_it_404s_when_the_bytes_are_gone_from_disk(): void
    {
        $this->fakeConverter();
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();
        $attachment = $this->uploadRaw($lead, 'proposal.docx', $this->fixture('proposal.docx'));

        Storage::disk(LeadAttachmentStore::DISK)->delete($attachment->path);

        $this->preview($lead, $attachment)->assertNotFound();
    }

    // ── 5. Capability flag ───────────────────────────────────────────────────────────────────

    public function test_the_listing_offers_document_preview_only_for_office_files_on_a_capable_server(): void
    {
        $this->fakeConverter();
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();

        $expected = [
            'proposal.docx' => true,
            'proposal.doc' => true,
            'deck.pptx' => true,
            'deck.ppt' => true,
            'bundle.zip' => false,
            'quote.pdf' => false,
            'book.xlsx' => false,
        ];
        foreach (array_keys($expected) as $name) {
            $this->uploadRaw($lead, $name, str_starts_with($name, 'proposal') || str_starts_with($name, 'deck')
                ? $this->fixture($name)
                : 'bytes');
        }

        $flags = collect($this->getJson("/api/v1/crm/leads/{$lead->id}/attachments")->assertOk()->json('data'))
            ->mapWithKeys(fn (array $row): array => [$row['name'] => $row['documentPreview']])
            ->all();

        foreach ($expected as $name => $flag) {
            $this->assertSame($flag, $flags[$name], "documentPreview for {$name}");
        }
    }

    public function test_a_server_with_no_gotenberg_configured_offers_no_preview_and_sends_nothing(): void
    {
        // The REAL converter with no URL — the state of every server until Gotenberg is set up.
        config(['office_preview.enabled' => true, 'office_preview.gotenberg.url' => null]);
        Http::preventStrayRequests();
        Http::fake();
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();
        $attachment = $this->uploadRaw($lead, 'proposal.docx', $this->fixture('proposal.docx'));

        $this->getJson("/api/v1/crm/leads/{$lead->id}/attachments")
            ->assertOk()
            ->assertJsonPath('data.0.documentPreview', false);

        $this->preview($lead, $attachment)
            ->assertStatus(503)
            ->assertJsonPath('message', DocumentPreviewException::UNAVAILABLE);

        Http::assertNothingSent();
    }

    public function test_switching_the_feature_off_withdraws_the_offer(): void
    {
        // The REAL converter, configured but switched off — the production kill switch.
        config(['office_preview.enabled' => false, 'office_preview.gotenberg.url' => self::GOTENBERG_URL]);
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();
        $this->uploadRaw($lead, 'proposal.docx', $this->fixture('proposal.docx'));

        $this->getJson("/api/v1/crm/leads/{$lead->id}/attachments")
            ->assertOk()
            ->assertJsonPath('data.0.documentPreview', false);
    }

    // ── 6. Deletion purges the cache; download is untouched ──────────────────────────────────

    public function test_deleting_an_attachment_removes_its_cached_previews_and_only_those(): void
    {
        $this->fakeConverter();
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();
        $doomed = $this->uploadRaw($lead, 'proposal.docx', $this->fixture('proposal.docx'));
        $kept = $this->uploadRaw($lead, 'deck.pptx', $this->fixture('deck.pptx'));

        $this->preview($lead, $doomed)->assertOk();
        $this->preview($lead, $kept)->assertOk();
        $this->assertCount(1, $this->cachedPreviews($doomed));

        $this->deleteJson("/api/v1/crm/leads/{$lead->id}/attachments/{$doomed->id}")->assertNoContent();

        $this->assertSame([], $this->cachedPreviews($doomed), 'Deleting a document must delete its PDF copy.');
        $this->assertCount(1, $this->cachedPreviews($kept), 'Another attachment\'s preview must survive.');
    }

    public function test_download_still_serves_the_original_office_file(): void
    {
        $this->fakeConverter();
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();
        $attachment = $this->uploadRaw($lead, 'proposal.docx', $this->fixture('proposal.docx'));

        $this->get("/api/v1/crm/leads/{$lead->id}/attachments/{$attachment->id}/download")
            ->assertOk()
            ->assertDownload('proposal.docx');
    }

    // ── Tenancy helpers (same shape as LeadUnassignTest) ─────────────────────────────────────

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

    private function actAs(User $actor): void
    {
        // Leaving the base tenant pinned would make the negative case pass because the harness
        // pointed elsewhere, not because tenancy refused anything.
        CurrentOrg::clear();
        Sanctum::actingAs($actor);
    }
}
