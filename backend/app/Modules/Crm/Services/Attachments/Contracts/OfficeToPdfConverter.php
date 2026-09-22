<?php

namespace App\Modules\Crm\Services\Attachments\Contracts;

use App\Modules\Crm\Services\Attachments\DocumentPreviewException;
use App\Modules\Crm\Services\Attachments\GotenbergConverter;
use App\Modules\Crm\Services\Attachments\OfficeFormat;
use Illuminate\Container\Attributes\Bind;

/**
 * Turns one Office document into one PDF.
 *
 * An interface so the preview service depends on the capability rather than on one engine: tests
 * substitute a fake to exercise caching and failure paths without a conversion service, and the
 * engine behind it — today Gotenberg, a separate private service — can change without touching the
 * preview service.
 *
 * Bound here with the container's #[Bind] attribute rather than in CrmServiceProvider, so the binding
 * lives next to the contract it satisfies.
 */
#[Bind(GotenbergConverter::class)]
interface OfficeToPdfConverter
{
    /**
     * Whether this server can convert at all. Drives the capability flag the client sees, so it must
     * be cheap and must never launch a conversion to find out.
     */
    public function isAvailable(): bool;

    /**
     * Convert `$sourcePath`, already admitted as `$format`, and write a validated PDF to `$targetPath`.
     *
     * Implementations must leave nothing behind on failure — no partial target, no temporary files —
     * and must throw only {@see DocumentPreviewException}.
     *
     * @throws DocumentPreviewException
     */
    public function convert(string $sourcePath, OfficeFormat $format, string $targetPath): void;
}
