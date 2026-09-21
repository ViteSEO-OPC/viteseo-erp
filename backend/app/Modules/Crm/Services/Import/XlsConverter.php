<?php

namespace App\Modules\Crm\Services\Import;

use App\Modules\Crm\Services\LeadImportService;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Reader\Xls as XlsReader;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Throwable;

/**
 * Legacy `.xls` (BIFF) → `.xlsx` normalization. The ONLY place PhpSpreadsheet is used.
 *
 * WHY A CONVERTER AND NOT A SECOND PARSER. The importer reads spreadsheets through OpenSpout, which
 * has no `.xls` reader and never will — it is a streaming reader for the XML-based OOXML/ODS
 * formats, and BIFF is a binary compound document. Rather than teach {@see LeadImportService} a
 * second parsing paradigm, `.xls` is normalized to `.xlsx` up front and then read by the EXISTING
 * OpenSpout pipeline. One parsing path, one set of semantics; header aliasing, value translation,
 * deduplication and validation cannot observe which format the rows came from.
 *
 * MEMORY IS THE BINDING CONSTRAINT, AND IT SCALES WITH CELLS — NOT FILE SIZE. PhpSpreadsheet builds
 * a Cell object per populated cell (~1 KB each), so a 10 MB BIFF file (~700K cells) would need
 * ~750 MB while production FPM workers are capped at 128 MB. {@see estimateCells()} therefore runs
 * FIRST and is deliberately cheap — `listWorksheetInfo()` reads only BIFF dimension records and
 * never materializes a workbook — so the caller can route a large file to the queue instead of
 * discovering the problem by exhausting memory mid-request.
 */
class XlsConverter
{
    /**
     * Cell count above which conversion must NOT run inside a web request.
     *
     * ~150K cells ≈ 13,600 rows × 11 columns, peaking around 200 MB inside the scoped limit below.
     * Sized against the measured real-world import (998 rows / 10,119 cells), which this clears by
     * roughly 15×. It is a ROUTING threshold, never a rejection: a file above it is converted on the
     * queue, where neither `max_execution_time` nor the nginx/Cloudflare edge timeouts apply.
     */
    public const SYNC_CELL_THRESHOLD = 150_000;

    /** Raised only for the duration of a conversion, then restored — never a global change. */
    private const CONVERSION_MEMORY_LIMIT = '256M';

    /** Web-request conversions are bounded well inside `max_execution_time` (30s in production). */
    private const CONVERSION_TIME_LIMIT = 20;

    /**
     * Populated cells in the first worksheet, read WITHOUT building the workbook.
     *
     * `listWorksheetInfo()` parses BIFF dimension records only, so this stays flat in memory
     * regardless of file size — which is the whole point: it must be safe to call on a 10 MB file
     * inside a 128 MB worker.
     *
     * @throws ValidationException when the file is not a readable `.xls`
     */
    public function estimateCells(string $path): int
    {
        try {
            $info = (new XlsReader)->listWorksheetInfo($path);
        } catch (Throwable $e) {
            throw $this->unreadable($e);
        }

        if ($info === []) {
            throw ValidationException::withMessages([
                'file' => 'The Excel file contains no worksheets.',
            ]);
        }

        $first = $info[0];

        return (int) ($first['totalRows'] ?? 0) * (int) ($first['totalColumns'] ?? 0);
    }

    /**
     * Convert `.xls` at $sourcePath into a NEW `.xlsx` file and return its path.
     *
     * The caller owns the returned file and MUST delete it — the two callers do so in a `finally`
     * (synchronous path) and after commit/failure/expiry (queued path). Nothing here is written to
     * `storage/` by default, so a leaked temp file cannot end up served, backed up, or committed.
     *
     * `setReadDataOnly(true)` skips styles, drawings and formatting: the importer consumes scalar
     * cell values only, so everything else is cost without benefit.
     *
     * @param  string|null  $targetPath  where to write; a temp file is created when omitted
     *
     * @throws ValidationException when the file cannot be read as a legacy workbook
     */
    public function convert(string $sourcePath, ?string $targetPath = null): string
    {
        $target = $targetPath ?? $this->temporaryTarget();

        // Scoped, not global. A global raise would multiply across every FPM worker; this costs the
        // ceiling on ONE worker for the duration of one conversion, and is always restored.
        $previousMemory = ini_get('memory_limit');
        ini_set('memory_limit', self::CONVERSION_MEMORY_LIMIT);

        // WEB REQUESTS ONLY. `max_execution_time` is 0 (unlimited) under CLI, which is exactly where
        // the LARGE conversions run — the queue worker. Clamping there would kill precisely the jobs
        // this ceiling is not meant to bound, and the limit is process-wide, so it would also outlive
        // this call and shorten everything the worker ran afterwards.
        $previousTime = (int) ini_get('max_execution_time');
        $boundTime = $previousTime > 0 && function_exists('set_time_limit');
        if ($boundTime) {
            @set_time_limit(self::CONVERSION_TIME_LIMIT);
        }

        try {
            $reader = new XlsReader;
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($sourcePath);

            (new XlsxWriter($spreadsheet))->save($target);

            // Release the object graph immediately rather than waiting for the request to end —
            // the caller still has to parse the result through OpenSpout.
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            return $target;
        } catch (Throwable $e) {
            // Never leave a half-written workbook behind for the caller to read.
            if (is_file($target)) {
                @unlink($target);
            }

            throw $this->unreadable($e);
        } finally {
            ini_set('memory_limit', $previousMemory === false ? '128M' : $previousMemory);

            // Restore the caller's budget — the limit is process-wide, so leaving 20s in place would
            // shorten every later request or job handled by this same process.
            if ($boundTime) {
                @set_time_limit($previousTime);
            }
        }
    }

    /** A unique `.xlsx` path in the system temp dir. Extension matters — the writer keys off it. */
    private function temporaryTarget(): string
    {
        $base = tempnam(sys_get_temp_dir(), 'crm-xls-');

        if ($base === false) {
            throw ValidationException::withMessages([
                'file' => 'Could not allocate a temporary file to convert the Excel workbook.',
            ]);
        }

        // tempnam() created the placeholder; the writer needs the .xlsx name, so drop the original.
        @unlink($base);

        return $base.'.xlsx';
    }

    /**
     * One user-facing failure message for every unreadable-workbook case — corrupt, truncated,
     * password-protected, or an older BIFF version. Surfaced as a normal 422 through the existing
     * preview, never a 500, and the underlying exception text is deliberately not echoed back.
     */
    private function unreadable(Throwable $previous): ValidationException
    {
        return ValidationException::withMessages([
            'file' => 'The file could not be read as a legacy Excel (.xls) workbook. '
                .'It may be corrupt or password-protected — please re-save it as .xlsx and try again.',
        ]);
    }
}
