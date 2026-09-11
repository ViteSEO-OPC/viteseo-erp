<?php

namespace App\Modules\Crm\Services\Attachments;

/**
 * The four Office formats that can be previewed by converting them to PDF — and nothing else.
 *
 * An enum rather than a list of strings so the allowlist is closed: a format is previewable only if
 * it has a case here. There is no default branch anywhere that could let a fifth format through.
 */
enum OfficeFormat: string
{
    case Doc = 'doc';
    case Docx = 'docx';
    case Ppt = 'ppt';
    case Pptx = 'pptx';

    /**
     * The format a filename CLAIMS to be, or null when it is not one of the four.
     *
     * Only a claim. {@see OfficeFormatDetector} accepts a file when its bytes agree with this, so the
     * extension narrows the question and the content answers it.
     */
    public static function fromFilename(?string $name): ?self
    {
        return self::tryFrom(strtolower(pathinfo((string) $name, PATHINFO_EXTENSION)));
    }

    /** DOCX and PPTX are ZIP-based Office Open XML; DOC and PPT are OLE compound files. */
    public function isOfficeOpenXml(): bool
    {
        return $this === self::Docx || $this === self::Pptx;
    }
}
