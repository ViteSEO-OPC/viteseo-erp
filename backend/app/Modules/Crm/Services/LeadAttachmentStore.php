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
     */
    public function store(Lead $lead, UploadedFile $file, ?User $uploader): LeadAttachment
    {
        $path = $file->store($this->directoryFor($lead), self::DISK);

        if ($path === false) {
            throw new \RuntimeException('Failed to store the uploaded file.');
        }

        try {
            return DB::transaction(function () use ($lead, $file, $uploader, $path): LeadAttachment {
                $attachment = new LeadAttachment();
                $attachment->lead_id = $lead->id;
                $attachment->user_id = $uploader?->id;
                $attachment->path = $path;
                // getClientOriginalName is client-controlled; it is stored as display text only and
                // is escaped/encoded wherever it is rendered or sent as a download filename.
                $attachment->original_name = $file->getClientOriginalName();
                $attachment->mime_type = $file->getClientMimeType();
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
