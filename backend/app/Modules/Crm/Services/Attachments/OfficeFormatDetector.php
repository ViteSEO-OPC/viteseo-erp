<?php

namespace App\Modules\Crm\Services\Attachments;

use ZipArchive;

/**
 * Decides whether a stored attachment really IS the Office format its name claims — from its bytes.
 *
 * The rule is conjunctive: the filename's extension must be one of the four {@see OfficeFormat}s AND
 * the content must be that same format. Neither alone is enough. The extension is client-supplied, and
 * `crm_lead_attachments.mime_type` is not authoritative either (rows written before it was derived
 * from content still hold the browser's string — see LeadAttachmentStore). So the type used here is
 * derived on the server from the file itself, every time, and never read from the database.
 *
 * WHAT "THE CONTENT IS THAT FORMAT" MEANS
 *
 *   DOCX / PPTX  A ZIP whose `[Content_Types].xml` declares the Word or PowerPoint MAIN part type
 *                exactly. That is the one declaration that distinguishes a Word document from a
 *                presentation, a workbook, or a macro-enabled variant: `.docm`/`.pptm` declare a
 *                different main type and are refused here, as is any package carrying a VBA project.
 *                The archive is bounded before it is inspected — entry count and total uncompressed
 *                size — so a ZIP bomb is refused by its directory, without being expanded.
 *
 *   DOC / PPT    An OLE compound file (its fixed 8-byte signature) that contains the format's main
 *                stream: `WordDocument` for Word, `PowerPoint Document` for PowerPoint. Stream names
 *                are stored as UTF-16LE in the compound file's directory, which is what is matched.
 *                This is a signature check, not a parser — the conversion service does the parsing,
 *                in its own container, and is told the format by the extension this check has just
 *                matched to the bytes.
 *
 * Anything else — a PDF renamed `.docx`, a workbook renamed `.doc`, an HTML page, a truncated file —
 * returns null and never reaches the converter.
 */
class OfficeFormatDetector
{
    private const ZIP_SIGNATURE = "PK\x03\x04";

    private const OLE_SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    /** Far beyond any real document (a large deck is a few hundred parts). */
    private const MAX_ZIP_ENTRIES = 2000;

    /** Declared uncompressed total. Refused from the central directory, before anything is inflated. */
    private const MAX_ZIP_UNCOMPRESSED_BYTES = 200 * 1024 * 1024;

    private const MAX_CONTENT_TYPES_BYTES = 1024 * 1024;

    /** Uploads are capped at 10 MB; this only guards the read itself. */
    private const MAX_OLE_READ_BYTES = 16 * 1024 * 1024;

    /** @var array<string, string> the exact main-part content type each OOXML format must declare */
    private const OOXML_MAIN_TYPES = [
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml',
    ];

    /** @var array<string, string> the stream each OLE format must contain */
    private const OLE_MAIN_STREAMS = [
        'doc' => 'WordDocument',
        'ppt' => 'PowerPoint Document',
    ];

    /**
     * @param  string  $absolutePath  a file on the PRIVATE disk, already authorized by the caller
     * @param  string|null  $originalName  the stored display name — used only for its extension
     */
    public function detect(string $absolutePath, ?string $originalName): ?OfficeFormat
    {
        $claimed = OfficeFormat::fromFilename($originalName);

        if ($claimed === null || ! is_file($absolutePath) || ! is_readable($absolutePath)) {
            return null;
        }

        $matches = $claimed->isOfficeOpenXml()
            ? $this->isOfficeOpenXml($absolutePath, self::OOXML_MAIN_TYPES[$claimed->value])
            : $this->isOleDocument($absolutePath, self::OLE_MAIN_STREAMS[$claimed->value]);

        return $matches ? $claimed : null;
    }

    private function isOfficeOpenXml(string $path, string $mainType): bool
    {
        if ($this->head($path, 4) !== self::ZIP_SIGNATURE) {
            return false;
        }

        $zip = new ZipArchive;

        // RDONLY: nothing is ever written back. CHECKCONS: refuse an archive whose directory and
        // entries disagree, which is how several malformed-ZIP tricks present.
        if ($zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            return false;
        }

        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ZIP_ENTRIES) {
                return false;
            }

            $declared = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    return false;
                }

                $declared += (int) $stat['size'];
                if ($declared > self::MAX_ZIP_UNCOMPRESSED_BYTES) {
                    return false;
                }

                // A VBA project makes it a macro document whatever its content types say.
                if (stripos((string) $stat['name'], 'vbaProject') !== false) {
                    return false;
                }
            }

            $contentTypes = $zip->getFromName('[Content_Types].xml', self::MAX_CONTENT_TYPES_BYTES);
            if (! is_string($contentTypes) || $contentTypes === '') {
                return false;
            }

            // Belt and braces for the macro-enabled variants, whose main types all say so.
            if (stripos($contentTypes, 'macroEnabled') !== false) {
                return false;
            }

            return preg_match('/ContentType\s*=\s*"'.preg_quote($mainType, '/').'"/i', $contentTypes) === 1;
        } finally {
            $zip->close();
        }
    }

    private function isOleDocument(string $path, string $mainStream): bool
    {
        if ($this->head($path, 8) !== self::OLE_SIGNATURE) {
            return false;
        }

        $bytes = @file_get_contents($path, false, null, 0, self::MAX_OLE_READ_BYTES);

        if (! is_string($bytes) || strlen($bytes) < 1536) {
            return false; // smaller than a header plus one directory sector: not a real compound file
        }

        return str_contains($bytes, mb_convert_encoding($mainStream, 'UTF-16LE', 'UTF-8'));
    }

    private function head(string $path, int $length): string
    {
        $bytes = @file_get_contents($path, false, null, 0, $length);

        return is_string($bytes) ? $bytes : '';
    }
}
