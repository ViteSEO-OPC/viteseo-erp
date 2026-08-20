<?php

namespace App\Modules\Hr\Services;

use Illuminate\Support\Facades\Storage;

/** Where HR evidence files live, and how a client is given access to one. */
final class PrivateFileService
{
    /** Not symlinked into public/, so nothing here is reachable without a signature. */
    public const DISK = 'local';

    /** How long an issued link stays valid. */
    public const LINK_TTL_MINUTES = 30;

    /** A short-lived signed URL for a stored file, or null when there is no file. */
    public static function url(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return Storage::disk(self::DISK)->temporaryUrl(
            $path,
            now()->addMinutes(self::LINK_TTL_MINUTES),
        );
    }
}
