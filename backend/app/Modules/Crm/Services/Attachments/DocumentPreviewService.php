<?php

namespace App\Modules\Crm\Services\Attachments;

use App\Modules\Crm\Models\LeadAttachment;
use App\Modules\Crm\Services\Attachments\Contracts\OfficeToPdfConverter;
use App\Modules\Crm\Services\LeadAttachmentStore;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * PDF previews of Office attachments: decides whether one may be made, makes it once, and keeps it.
 *
 * The controller authorizes; this class assumes the caller may see the attachment and answers only
 * "what PDF represents it". Four rules, each closing a different failure mode:
 *
 *  1. ADMISSION BY CONTENT. {@see OfficeFormatDetector} must agree that the bytes are the Office
 *     format the name claims. Anything else is refused before a process is ever started.
 *
 *  2. ONE CONVERSION AT A TIME. A server-wide lock serializes conversions, so ten people opening ten
 *     decks cost ten sequential conversions of ~1s rather than ten simultaneous uploads to the
 *     conversion service, each holding a PHP worker while it waits. A request that cannot get the
 *     lock quickly is told to retry (503) instead of piling up behind the others.
 *
 *  3. CONVERT ONCE, BY CONTENT. The PDF is cached on the PRIVATE disk under the attachment's id AND
 *     the SHA-256 of its bytes, so a repeat view is a file read, and a cache entry can only ever be
 *     served for the exact bytes it was made from. The cache sits beside the attachments on the
 *     private disk and is reached only through the authorized endpoint — never by URL.
 *
 *  4. A FAILURE IS REMEMBERED, BRIEFLY. A document that failed to convert is not re-attempted for
 *     `failure_ttl_seconds`, so a corrupt or hostile file cannot make every click cost a full
 *     conversion. It expires rather than sticking, because "the converter timed out under load" and
 *     "this file is broken" look the same from here.
 */
class DocumentPreviewService
{
    /** Private-disk directory for generated PDFs, one sub-directory per lead like the attachments. */
    public const CACHE_DIRECTORY = 'lead-attachment-previews';

    /** Server-wide: the bound is on concurrent conversions, not per lead or per user. */
    private const LOCK_KEY = 'crm:office-preview:convert';

    public function __construct(
        private readonly OfficeToPdfConverter $converter,
        private readonly OfficeFormatDetector $detector,
    ) {}

    /**
     * Whether to OFFER a preview for this attachment — the capability flag the client reads.
     *
     * Deliberately cheap and deliberately optimistic about content: it looks only at the claimed
     * format and whether this server can convert at all. The bytes are checked when the preview is
     * actually requested, so a mislabelled file costs one refused request, never a wrong answer.
     */
    public function isOfferedFor(LeadAttachment $attachment): bool
    {
        return OfficeFormat::fromFilename($attachment->original_name) !== null
            && $this->converter->isAvailable();
    }

    /**
     * The private-disk path of a PDF representing this attachment, converting it first if needed.
     *
     * @throws DocumentPreviewException the only exception this method throws
     */
    public function pdfFor(LeadAttachment $attachment): string
    {
        $disk = Storage::disk(LeadAttachmentStore::DISK);
        $source = $disk->path($attachment->path);

        // Content first, availability second: a ZIP is "cannot be previewed" on every server, and
        // saying "try again later" about it would be untrue.
        $format = $this->detector->detect($source, $attachment->original_name)
            ?? throw DocumentPreviewException::unsupported();

        if (! $this->converter->isAvailable()) {
            throw DocumentPreviewException::unavailable();
        }

        $hash = hash_file('sha256', $source);
        if ($hash === false) {
            throw DocumentPreviewException::unreadable();
        }

        $cached = $this->cachePath($attachment, $hash);
        if ($disk->exists($cached)) {
            return $cached;
        }

        $failureKey = "crm:office-preview:failed:{$attachment->id}:{$hash}";
        if (Cache::has($failureKey)) {
            throw DocumentPreviewException::unreadable();
        }

        // Held for longer than a conversion can take (one request, bounded by `timeout_seconds`), so
        // a lock can never expire under a conversion that is still running and admit a second one.
        $lock = Cache::lock(self::LOCK_KEY, (int) config('office_preview.timeout_seconds') + 15);

        try {
            return $lock->block(
                (int) config('office_preview.lock_wait_seconds'),
                function () use ($disk, $source, $format, $cached, $failureKey): string {
                    // Someone else may have produced it while this request waited for the lock.
                    if ($disk->exists($cached)) {
                        return $cached;
                    }

                    $disk->makeDirectory(dirname($cached));

                    try {
                        $this->converter->convert($source, $format, $disk->path($cached));
                    } catch (DocumentPreviewException $e) {
                        if ($e->status === 422) {
                            Cache::put($failureKey, true, (int) config('office_preview.failure_ttl_seconds'));
                        }

                        throw $e;
                    }

                    return $cached;
                },
            );
        } catch (LockTimeoutException $e) {
            throw DocumentPreviewException::busy($e);
        }
    }

    /**
     * Delete every cached PDF of this attachment. Called when the attachment is deleted, so removing
     * a document removes every copy of its contents — the cache included.
     */
    public function forget(LeadAttachment $attachment): void
    {
        $disk = Storage::disk(LeadAttachmentStore::DISK);
        $prefix = $attachment->id.'-';

        foreach ($disk->files(self::CACHE_DIRECTORY.'/'.$attachment->lead_id) as $file) {
            if (str_starts_with(basename($file), $prefix)) {
                $disk->delete($file);
            }
        }
    }

    /** Both path segments are integers and the hash is hex, so nothing client-supplied reaches it. */
    private function cachePath(LeadAttachment $attachment, string $hash): string
    {
        return self::CACHE_DIRECTORY."/{$attachment->lead_id}/{$attachment->id}-{$hash}.pdf";
    }
}
