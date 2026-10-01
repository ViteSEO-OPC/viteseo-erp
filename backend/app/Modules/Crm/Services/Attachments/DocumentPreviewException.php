<?php

namespace App\Modules\Crm\Services\Attachments;

use RuntimeException;
use Throwable;

/**
 * An Office document the preview will not or cannot show.
 *
 * Same contract as {@see UnreadableSpreadsheetException}: the message is always one of the constants
 * below, so the endpoint can echo it to the user without ever leaking a path, a URL, the conversion
 * service's diagnostic or a class name. The real cause is kept as `$previous`, for the log only.
 *
 * Each case also carries its HTTP status, because the right answer differs: a file that can never be
 * previewed is a 422, while a busy or unavailable converter is a 503 that says "try again", not
 * "this file is broken".
 */
class DocumentPreviewException extends RuntimeException
{
    /** Not one of the four formats, or its bytes are not what its name says. */
    public const UNSUPPORTED = 'This file can’t be previewed. Download it to open it.';

    /** Admitted, but the converter failed, timed out, or produced something that is not a usable PDF. */
    public const UNREADABLE = 'This document could not be prepared for preview. It may be corrupt, password-protected, or too complex — download it to open it.';

    /** Another conversion holds the lock. */
    public const BUSY = 'Another document is being prepared for preview. Please try again in a moment.';

    /** No conversion service is configured, it is switched off, or it could not be used just now. */
    public const UNAVAILABLE = 'Document preview isn’t available right now. Download the file to open it.';

    public function __construct(string $message, public readonly int $status = 422, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function unsupported(): self
    {
        return new self(self::UNSUPPORTED, 422);
    }

    public static function unreadable(?Throwable $previous = null): self
    {
        return new self(self::UNREADABLE, 422, $previous);
    }

    public static function busy(?Throwable $previous = null): self
    {
        return new self(self::BUSY, 503, $previous);
    }

    public static function unavailable(?Throwable $previous = null): self
    {
        return new self(self::UNAVAILABLE, 503, $previous);
    }
}
