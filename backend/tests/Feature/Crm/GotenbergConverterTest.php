<?php

namespace Tests\Feature\Crm;

use App\Modules\Crm\Services\Attachments\DocumentPreviewException;
use App\Modules\Crm\Services\Attachments\GotenbergConverter;
use App\Modules\Crm\Services\Attachments\OfficeFormat;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Gotenberg converter against a FAKED Gotenberg HTTP API — no real service is needed.
 *
 * What is pinned:
 *  - each of the four formats is uploaded as the only multipart part, `files`, under a fixed name,
 *    with the configured credentials, to the one conversion route — and nothing else is sent;
 *  - the PDF in the response is validated before it is placed, and placed atomically, 0600;
 *  - every failure says whose fault it is (the document: 422, or the service: 503) and leaves
 *    nothing behind;
 *  - without a usable URL, or switched off, it is unavailable and sends nothing at all.
 *
 * Http::preventStrayRequests() makes any request that no fake answers fail the test, so nothing here
 * can reach a real network.
 */
class GotenbergConverterTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../Fixtures/office';

    private const BASE_URL = 'http://gotenberg.internal:3000';

    private const ENDPOINT = self::BASE_URL.'/forms/libreoffice/convert';

    private const PDF = "%PDF-1.4\n1 0 obj <<>> endobj\ntrailer <<>>\n%%EOF\n";

    private string $dir;

    private string $target;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->dir = sys_get_temp_dir().'/gotenberg-test-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
        $this->target = $this->dir.'/result.pdf';

        config([
            'office_preview.enabled' => true,
            'office_preview.gotenberg.url' => self::BASE_URL,
            'office_preview.gotenberg.username' => 'crm-preview',
            'office_preview.gotenberg.password' => 'not-a-real-secret',
            'office_preview.timeout_seconds' => 20,
            'office_preview.max_pdf_bytes' => 50 * 1024 * 1024,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function converter(): GotenbergConverter
    {
        return app(GotenbergConverter::class);
    }

    private function convert(OfficeFormat $format = OfficeFormat::Docx, ?string $source = null): void
    {
        $this->converter()->convert($source ?? self::FIXTURES.'/proposal.docx', $format, $this->target);
    }

    /** Converting must fail with exactly this message and status, and leave no file behind. */
    private function assertRefused(string $message, int $status): DocumentPreviewException
    {
        try {
            $this->convert();
            $this->fail('The conversion should have been refused.');
        } catch (DocumentPreviewException $e) {
            $this->assertSame($message, $e->getMessage());
            $this->assertSame($status, $e->status);
            $this->assertFileDoesNotExist($this->target, 'A failed conversion must not leave a target behind.');
            $this->assertSame([], glob($this->target.'.part-*') ?: [], 'No partial file may be left behind.');

            return $e;
        }
    }

    // ── Success: one upload per format ───────────────────────────────────────────────────────

    /** @return array<string, array{OfficeFormat, string}> */
    public static function officeFormats(): array
    {
        return [
            'doc' => [OfficeFormat::Doc, 'proposal.doc'],
            'docx' => [OfficeFormat::Docx, 'proposal.docx'],
            'ppt' => [OfficeFormat::Ppt, 'deck.ppt'],
            'pptx' => [OfficeFormat::Pptx, 'deck.pptx'],
        ];
    }

    #[DataProvider('officeFormats')]
    public function test_each_format_is_uploaded_to_gotenberg_and_the_returned_pdf_is_placed(OfficeFormat $format, string $fixture): void
    {
        Http::fake([self::ENDPOINT => Http::response(self::PDF, 200, ['Content-Type' => 'application/pdf'])]);
        $bytes = (string) file_get_contents(self::FIXTURES.'/'.$fixture);

        $this->convert($format, self::FIXTURES.'/'.$fixture);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::ENDPOINT
            && $request->isMultipart()
            && $request->hasFile('files', $bytes, "document.{$format->value}")
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('crm-preview:not-a-real-secret')));

        $this->assertSame(self::PDF, file_get_contents($this->target));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->target)), -4), 'Private-disk permissions.');
        $this->assertSame([], glob($this->target.'.part-*') ?: []);
    }

    public function test_only_the_document_is_sent_never_its_name_and_never_a_url_to_fetch(): void
    {
        Http::fake([self::ENDPOINT => Http::response(self::PDF)]);
        $named = $this->dir.'/Acme Confidential Pricing.docx';
        copy(self::FIXTURES.'/proposal.docx', $named);

        $this->convert(OfficeFormat::Docx, $named);

        Http::assertSent(function (Request $request): bool {
            $parts = $request->data();

            return count($parts) === 1
                && $parts[0]['name'] === 'files'
                && $parts[0]['filename'] === 'document.docx'
                && ! str_contains($request->body(), 'Acme Confidential Pricing')
                && ! str_contains($request->body(), 'downloadFrom');
        });
    }

    public function test_without_credentials_no_authorization_header_is_sent(): void
    {
        config(['office_preview.gotenberg.username' => null, 'office_preview.gotenberg.password' => null]);
        Http::fake([self::ENDPOINT => Http::response(self::PDF)]);

        $this->convert();

        Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
    }

    public function test_a_path_prefix_and_trailing_slash_in_the_url_are_respected(): void
    {
        config(['office_preview.gotenberg.url' => 'https://convert.internal/gotenberg/']);
        Http::fake(['https://convert.internal/gotenberg/forms/libreoffice/convert' => Http::response(self::PDF)]);

        $this->convert();

        Http::assertSentCount(1);
        $this->assertFileExists($this->target);
    }

    // ── Failures the document is responsible for: 422, remembered by the service ───────────

    /** @return array<string, array{int, string}> */
    public static function documentFailures(): array
    {
        return [
            'rejected by Gotenberg or LibreOffice' => [400, 'LibreOffice failed to process a document: /tmp/abc/document.docx'],
            'larger than the service accepts' => [413, 'Request Entity Too Large'],
            'outran Gotenberg\'s own time limit' => [503, 'Service Unavailable: context deadline exceeded'],
        ];
    }

    #[DataProvider('documentFailures')]
    public function test_an_answer_about_the_document_is_a_422(int $status, string $body): void
    {
        Http::fake([self::ENDPOINT => Http::response($body, $status, ['Content-Type' => 'text/plain'])]);

        $e = $this->assertRefused(DocumentPreviewException::UNREADABLE, 422);

        // Gotenberg's own text is kept for the log only — never in the user-facing message.
        $this->assertStringContainsString("Gotenberg answered {$status}", (string) $e->getPrevious()?->getMessage());
        $this->assertStringNotContainsString($body, $e->getMessage());
    }

    // ── Failures of the service: 503, never held against the document ───────────────────────

    /** @return array<string, array{int, array<string, string>}> */
    public static function serviceFailures(): array
    {
        return [
            'internal error' => [500, []],
            'credentials refused' => [401, []],
            'forbidden' => [403, []],
            'route not found (wrong URL)' => [404, []],
            'a redirect, not followed' => [302, ['Location' => 'http://elsewhere.example/forms/libreoffice/convert']],
        ];
    }

    /** @param  array<string, string>  $headers */
    #[DataProvider('serviceFailures')]
    public function test_an_answer_about_the_service_is_a_503(int $status, array $headers): void
    {
        Http::fake([self::ENDPOINT => Http::response('', $status, $headers)]);

        $this->assertRefused(DocumentPreviewException::UNAVAILABLE, 503);

        // A redirect in particular must not be followed: the document goes nowhere else.
        Http::assertSentCount(1);
    }

    public function test_an_unreachable_service_is_a_503(): void
    {
        Http::fake([self::ENDPOINT => Http::failedConnection('cURL error 7: Failed to connect to gotenberg.internal port 3000')]);

        $e = $this->assertRefused(DocumentPreviewException::UNAVAILABLE, 503);

        $this->assertInstanceOf(ConnectionException::class, $e->getPrevious(), 'The cause is kept for the log.');
    }

    public function test_a_timeout_is_a_503(): void
    {
        Http::fake([self::ENDPOINT => Http::failedConnection('cURL error 28: Operation timed out after 20001 milliseconds')]);

        $this->assertRefused(DocumentPreviewException::UNAVAILABLE, 503);
    }

    // ── The PDF is validated, not trusted ────────────────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function unusableBodies(): array
    {
        return [
            'not a pdf' => ['<html>not a pdf</html>'],
            'truncated pdf' => ["%PDF-1.4\n1 0 obj <<>> endobj\n"],
            'empty body' => [''],
        ];
    }

    #[DataProvider('unusableBodies')]
    public function test_a_success_status_with_an_unusable_body_is_refused(string $body): void
    {
        Http::fake([self::ENDPOINT => Http::response($body, 200, ['Content-Type' => 'application/pdf'])]);

        $this->assertRefused(DocumentPreviewException::UNREADABLE, 422);
    }

    public function test_an_oversized_pdf_is_refused(): void
    {
        config(['office_preview.max_pdf_bytes' => 16]);
        Http::fake([self::ENDPOINT => Http::response(self::PDF)]);

        $this->assertRefused(DocumentPreviewException::UNREADABLE, 422);
    }

    public function test_an_unreadable_source_is_refused_without_a_request(): void
    {
        Http::fake();

        try {
            $this->convert(OfficeFormat::Docx, $this->dir.'/missing.docx');
            $this->fail('The conversion should have been refused.');
        } catch (DocumentPreviewException $e) {
            $this->assertSame(DocumentPreviewException::UNREADABLE, $e->getMessage());
        }

        Http::assertNothingSent();
    }

    // ── Availability: configuration decides, and nothing is sent without it ─────────────────

    public function test_without_a_url_it_is_unavailable_and_sends_nothing(): void
    {
        config(['office_preview.gotenberg.url' => null]);
        Http::fake();

        $this->assertFalse($this->converter()->isAvailable());
        $this->assertRefused(DocumentPreviewException::UNAVAILABLE, 503);
        Http::assertNothingSent();
    }

    public function test_the_switch_turns_it_off_even_with_a_url(): void
    {
        config(['office_preview.enabled' => false]);
        Http::fake();

        $this->assertFalse($this->converter()->isAvailable());
        $this->assertRefused(DocumentPreviewException::UNAVAILABLE, 503);
        Http::assertNothingSent();
    }

    public function test_a_usable_url_makes_it_available(): void
    {
        $this->assertTrue($this->converter()->isAvailable());
    }

    /** @return array<string, array{string}> */
    public static function unusableUrls(): array
    {
        return [
            'blank' => ['   '],
            'no scheme' => ['gotenberg:3000'],
            'file scheme' => ['file:///etc/passwd'],
            'ftp scheme' => ['ftp://gotenberg.internal'],
            'credentials in the url' => ['http://user:secret@gotenberg.internal:3000'],
            'a query string' => ['http://gotenberg.internal:3000?next=http://elsewhere'],
            'a fragment' => ['http://gotenberg.internal:3000#x'],
        ];
    }

    #[DataProvider('unusableUrls')]
    public function test_a_url_that_is_not_a_plain_http_base_is_unusable(string $url): void
    {
        config(['office_preview.gotenberg.url' => $url]);
        Http::fake();

        $this->assertFalse($this->converter()->isAvailable());
        $this->assertRefused(DocumentPreviewException::UNAVAILABLE, 503);
        Http::assertNothingSent();
    }
}
