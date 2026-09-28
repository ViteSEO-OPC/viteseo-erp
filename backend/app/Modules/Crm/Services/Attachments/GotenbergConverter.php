<?php

namespace App\Modules\Crm\Services\Attachments;

use App\Modules\Crm\Services\Attachments\Contracts\OfficeToPdfConverter;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * DOC/DOCX/PPT/PPTX → PDF by Gotenberg, a separate private conversion service, one untrusted file at
 * a time.
 *
 * Nothing Office-related runs on this server. The document is uploaded to Gotenberg's LibreOffice
 * route and the PDF comes back in the same HTTP response; the parsing of the untrusted file happens
 * inside Gotenberg's own container, which holds none of this application's secrets or files.
 *
 *  ── PUSH, NEVER PULL ──────────────────────────────────────────────────────────────────────────
 *  The bytes travel in the request body as the multipart `files` part — the only part sent. Gotenberg
 *  is never handed a URL to fetch, so no attachment is ever given an address and the converter needs
 *  no route back into this application. Redirects are not followed: a converter address that answers
 *  with one is misconfigured, and following it would send the document somewhere else.
 *
 *  ── A FIXED NAME ──────────────────────────────────────────────────────────────────────────────
 *  The part is named `document.{ext}`. Gotenberg chooses how to read a file by its extension, and that
 *  extension is the one {@see OfficeFormatDetector} has already matched to the bytes. The uploader's
 *  filename never leaves this server.
 *
 *  ── CREDENTIALS ───────────────────────────────────────────────────────────────────────────────
 *  Basic auth from `office_preview.gotenberg`, server-side only. The URL itself may not carry
 *  credentials (see {@see endpoint()}), so an error message that quotes it cannot quote a secret.
 *
 *  ── BOUNDS ────────────────────────────────────────────────────────────────────────────────────
 *  A connect timeout, a total timeout, and no retries: a retry is the next click, and the preview
 *  service's lock and failure memory already decide when that may be. The response is written
 *  straight to disk rather than held in memory, and refused beyond `max_pdf_bytes`.
 *
 *  ── WHOSE FAULT ───────────────────────────────────────────────────────────────────────────────
 *  The answer decides whether the failure is remembered against the DOCUMENT:
 *
 *    400  Gotenberg or LibreOffice rejected the document       → UNREADABLE, 422, remembered
 *    413  larger than the service accepts                      → UNREADABLE, 422, remembered
 *    503  the conversion outran Gotenberg's own time limit     → UNREADABLE, 422, remembered
 *    anything else, or no answer at all — unreachable, timed
 *    out here, credentials refused, a redirect, an internal
 *    error                                                     → UNAVAILABLE, 503, not remembered
 *
 *  So an outage of the service never marks a good document as broken.
 *
 *  ── OUTPUT ────────────────────────────────────────────────────────────────────────────────────
 *  A success status is not trusted on its own. The body is accepted only if it is within
 *  `max_pdf_bytes`, begins `%PDF-` and ends with an `%%EOF` marker. It is written beside the target
 *  and renamed into place, so a reader never sees a half-written PDF, and it is 0600 like every other
 *  file on the private disk.
 */
class GotenbergConverter implements OfficeToPdfConverter
{
    /** Gotenberg's route for converting office documents with its bundled LibreOffice. */
    public const ROUTE = '/forms/libreoffice/convert';

    private const CONNECT_TIMEOUT_SECONDS = 5;

    /** The answers that describe the document rather than the service — see the class docblock. */
    private const DOCUMENT_FAILURES = [400, 413, 503];

    /** How much of an error body is kept for the log. It is Gotenberg's text, never shown to a user. */
    private const ERROR_EXCERPT_BYTES = 300;

    public function isAvailable(): bool
    {
        return (bool) config('office_preview.enabled') && $this->endpoint() !== null;
    }

    public function convert(string $sourcePath, OfficeFormat $format, string $targetPath): void
    {
        $endpoint = $this->endpoint();

        if (! config('office_preview.enabled') || $endpoint === null) {
            throw DocumentPreviewException::unavailable();
        }

        $partial = $targetPath.'.part-'.bin2hex(random_bytes(6));

        try {
            $document = @file_get_contents($sourcePath);
            if ($document === false) {
                throw new RuntimeException('Could not read the document.');
            }

            $response = $this->send($endpoint, $document, $format, $partial);

            if (! $response->successful()) {
                $cause = new RuntimeException(sprintf(
                    'Gotenberg answered %d: %s',
                    $response->status(),
                    $this->excerpt($partial),
                ));

                throw in_array($response->status(), self::DOCUMENT_FAILURES, true)
                    ? DocumentPreviewException::unreadable($cause)
                    : DocumentPreviewException::unavailable($cause);
            }

            $this->assertUsablePdf($partial);

            if (! @chmod($partial, 0600) || ! @rename($partial, $targetPath)) {
                throw new RuntimeException('Could not store the generated PDF.');
            }
        } catch (DocumentPreviewException $e) {
            throw $e;
        } catch (Throwable $e) {
            // An unusable PDF, an unreadable source, a disk that refused the write: all one sentence
            // to the user, the real cause kept for the log.
            throw DocumentPreviewException::unreadable($e);
        } finally {
            if (is_file($partial)) {
                @unlink($partial);
            }
        }
    }

    /** The one request. No answer at all is the service's failure, never the document's. */
    private function send(string $endpoint, string $document, OfficeFormat $format, string $sink): Response
    {
        try {
            return $this->client()
                ->sink($sink)
                ->attach('files', $document, "document.{$format->value}")
                ->post($endpoint);
        } catch (Throwable $e) {
            throw DocumentPreviewException::unavailable($e);
        }
    }

    private function client(): PendingRequest
    {
        $request = Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout((int) config('office_preview.timeout_seconds'))
            ->withoutRedirecting();

        $username = config('office_preview.gotenberg.username');
        if (is_string($username) && $username !== '') {
            $request->withBasicAuth($username, (string) config('office_preview.gotenberg.password'));
        }

        return $request;
    }

    /**
     * The conversion URL, or null when no usable one is configured — which is what keeps a server
     * Download-only. A plain http(s) base only: no credentials, query or fragment. Credentials belong
     * in the username and password settings, where they travel as a header and never appear in a URL
     * that a connection error might quote.
     */
    private function endpoint(): ?string
    {
        $base = config('office_preview.gotenberg.url');
        if (! is_string($base) || ($base = trim($base)) === '') {
            return null;
        }

        $parts = parse_url($base);
        if ($parts === false
            || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || ($parts['host'] ?? '') === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            return null;
        }

        return rtrim($base, '/').self::ROUTE;
    }

    private function assertUsablePdf(string $pdf): void
    {
        if (! is_file($pdf) || is_link($pdf)) {
            throw new RuntimeException('Gotenberg returned no PDF.');
        }

        $size = (int) filesize($pdf);
        if ($size < 8 || $size > (int) config('office_preview.max_pdf_bytes')) {
            throw new RuntimeException("The generated PDF is an unacceptable size ({$size} bytes).");
        }

        $head = (string) @file_get_contents($pdf, false, null, 0, 5);
        $tail = (string) @file_get_contents($pdf, false, null, max(0, $size - 1024));

        if ($head !== '%PDF-' || ! str_contains($tail, '%%EOF')) {
            throw new RuntimeException('The generated file is not a complete PDF.');
        }
    }

    /** The start of an error body, flattened to one printable line, for the log only. */
    private function excerpt(string $path): string
    {
        $head = is_file($path) ? (string) @file_get_contents($path, false, null, 0, self::ERROR_EXCERPT_BYTES) : '';

        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $head));
    }
}
