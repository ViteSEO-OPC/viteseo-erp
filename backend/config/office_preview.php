<?php

/*
|--------------------------------------------------------------------------
| Office document preview (DOC / DOCX / PPT / PPTX → PDF)
|--------------------------------------------------------------------------
|
| Lead attachments in these four formats are previewed by converting them to
| PDF with Gotenberg (gotenberg.dev), a SEPARATE conversion service, and showing
| the PDF in the existing viewer. Laravel uploads the document to it and reads
| the PDF back from the same response; nothing Office-related is installed or
| run on this server. See App\Modules\Crm\Services\Attachments\GotenbergConverter.
|
| The feature is CAPABILITY-GATED, not assumed. It is offered only when it is
| enabled here AND a Gotenberg URL is configured, so a server without one needs
| no configuration at all: its Office files simply stay Download-only, exactly
| as before. Nothing breaks and nothing is promised.
|
| GOTENBERG MUST STAY PRIVATE. It converts whatever it is sent, so it listens on
| a private address only (loopback or the private network) and is never
| published through the public web server. Run it with basic auth
| (--api-enable-basic-auth) and with its URL-fetching features off
| (--api-disable-download-from, --libreoffice-deny-private-ips). The values
| below are server-side only; the browser never sees them.
|
| Every number below is a bound on one conversion of one untrusted file.
|
*/

return [

    // Off switch. Leave true; whether a Gotenberg URL is configured is what
    // actually decides.
    'enabled' => (bool) env('OFFICE_PREVIEW_ENABLED', true),

    'gotenberg' => [
        // Base URL of the private Gotenberg service, e.g. http://127.0.0.1:3000.
        // Unset = Download-only. A plain http(s) address: no credentials, query
        // or fragment in it — credentials go below.
        'url' => env('GOTENBERG_URL'),

        // Gotenberg's basic-auth pair (GOTENBERG_API_BASIC_AUTH_USERNAME and
        // _PASSWORD on the service side). Leave unset only for a service that
        // runs without --api-enable-basic-auth.
        'username' => env('GOTENBERG_USERNAME'),
        'password' => env('GOTENBERG_PASSWORD'),
    ],

    // Wall clock for one conversion request, connection included. A typical
    // document converts in ~1-2s; this only ever stops a pathological one. Kept
    // well inside a 30s web request once the lock wait below is added to it.
    //
    // Set Gotenberg's own --api-timeout BELOW this. Then a document that takes
    // too long is answered by Gotenberg (503 — remembered as that document
    // failing), not by this timeout, which reads as the service being unreachable
    // and is deliberately not held against the document.
    'timeout_seconds' => (int) env('OFFICE_PREVIEW_TIMEOUT', 20),

    // A generated PDF larger than this is discarded rather than cached or served.
    'max_pdf_bytes' => (int) env('OFFICE_PREVIEW_MAX_PDF_BYTES', 50 * 1024 * 1024),

    // Conversions run one at a time per server. A request that cannot get the
    // lock within this many seconds is told to try again (503) instead of queueing
    // unboundedly behind others.
    'lock_wait_seconds' => (int) env('OFFICE_PREVIEW_LOCK_WAIT', 8),

    // A document that failed to convert is not retried for this long, so a corrupt
    // or hostile file cannot make every click cost a full conversion attempt.
    'failure_ttl_seconds' => (int) env('OFFICE_PREVIEW_FAILURE_TTL', 600),

];
