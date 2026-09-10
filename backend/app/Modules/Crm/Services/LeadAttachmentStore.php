<?php

namespace App\Modules\Crm\Services;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Writes and removes lead attachment files. The controller stays thin: it authorizes and hands the
 * UploadedFile here, so the disk name, the per-lead path layout and the row/bytes ordering live in
 * one place.
 *
 * Files go on the PRIVATE disk. A lead's documents inherit the lead's ownership boundary, so they
 * must not be reachable by URL guessing the way a `public` disk would allow — reads go through the
 * authorized download endpoint instead.
 */
class LeadAttachmentStore
{
    /** The private disk (storage/app/private). Deliberately NOT `public` — see the class docblock. */
    public const DISK = 'local';

    /**
     * Persist an uploaded file against a lead and return its metadata row.
     *
     * The bytes are written first, then the row; if the row insert fails the orphaned file is
     * removed, so the disk never accumulates files with no record pointing at them. The stored
     * filename is randomised by Laravel — the client's name is kept only as display metadata and
     * never becomes part of a path.
     *
     * `mime_type` is DERIVED FROM THE CONTENT (`getMimeType()`, which guesses from the bytes), not
     * copied from the request's `Content-Type` part header as it once was. The client-supplied value
     * was display metadata for as long as the only consumers were an icon and an `<img>` vs
     * `<iframe>` choice; it stops being harmless the moment anything routes a file INTO A PARSER by
     * it. Rows written before this change still hold the client's string, so nothing may treat this
     * column as authoritative either — {@see \App\Modules\Crm\Services\Attachments\SpreadsheetPreviewReader}
     * re-identifies the format from the bytes on disk and never consults it.
     */
    public function store(Lead $lead, UploadedFile $file, ?User $uploader): LeadAttachment
    {
        // BEFORE the move, not after: getMimeType() inspects the bytes at the temp path, and
        // store() moves the file out from under it. getClientMimeType() had no such ordering
        // requirement, which is why this was previously read inside the transaction below.
        $mimeType = $file->getMimeType() ?? 'application/octet-stream';

        $path = $file->store($this->directoryFor($lead), self::DISK);

        if ($path === false) {
            throw new \RuntimeException('Failed to store the uploaded file.');
        }

        try {
            return DB::transaction(function () use ($lead, $file, $uploader, $path, $mimeType): LeadAttachment {
                $attachment = new LeadAttachment();
                $attachment->lead_id = $lead->id;
                $attachment->user_id = $uploader?->id;
                $attachment->path = $path;
                // getClientOriginalName is client-controlled; it is stored as display text only and
                // is escaped/encoded wherever it is rendered or sent as a download filename.
                $attachment->original_name = $file->getClientOriginalName();
                $attachment->mime_type = $mimeType;
                $attachment->size = $file->getSize() ?: 0;
                $attachment->save();

                return $attachment;
            });
        } catch (\Throwable $e) {
            Storage::disk(self::DISK)->delete($path);

            throw $e;
        }
    }

    /**
     * Soft-delete the row and remove the bytes.
     *
     * The file is deleted for real while the row is only soft-deleted: the row is retained for the
     * audit trail, but keeping the bytes of a "deleted" document would defeat the point of deleting
     * it. A soft-deleted attachment is therefore metadata only and can never be downloaded again —
     * the download endpoint resolves through the non-trashed relation.
     */
    public function remove(LeadAttachment $attachment): void
    {
        $path = $attachment->path;

        DB::transaction(function () use ($attachment): void {
            $attachment->delete();
        });

        Storage::disk(self::DISK)->delete($path);
    }

    /** Per-lead directory, so a lead's files are grouped on disk and easy to audit or purge. */
    private function directoryFor(Lead $lead): string
    {
        return "lead-attachments/{$lead->id}";
    }
}
