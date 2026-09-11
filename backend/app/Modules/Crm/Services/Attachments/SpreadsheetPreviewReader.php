<?php

namespace App\Modules\Crm\Services\Attachments;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use Throwable;

/**
 * Reads a BOUNDED window out of an untrusted `.xlsx` / `.xls` workbook, for inline preview only.
 *
 * WHY A WINDOW AND NOT A DOCUMENT. This is a preview, not a viewer: Download remains the way to get
 * the real file. Reading a fixed 200x30 corner of the first sheet is what makes an untrusted upload
 * safe to open inside a web request at all — see the four bounds below, each of which closes a
 * different failure mode.
 *
 * ── HOW THE FORMAT IS DECIDED ────────────────────────────────────────────────────────────────
 * By CONTENT, never by the database. `crm_lead_attachments.mime_type` and `original_name` are both
 * derived from what the client sent; the only content-based check the upload performed (`mimes:`)
 * was never persisted, and rows predating that fix hold a fully client-supplied MIME string. So
 * dispatch here runs {@see IOFactory::identify()} against the bytes on disk, restricted to the two
 * readers in {@see FORMATS} — the restriction matters, because PhpSpreadsheet's Html and Csv readers
 * accept almost any text and would happily claim a file that is neither. A PDF renamed `.xlsx`
 * therefore fails identification and 422s; it can never reach a parser.
 *
 * ── THE FOUR BOUNDS ──────────────────────────────────────────────────────────────────────────
 * 1. FORMAT allowlist (above) — only two readers ever see the bytes.
 * 2. {@see BoundedWindowFilter} — the read filter is the real memory bound. PhpSpreadsheet builds a
 *    Cell object (~1 KB) per populated cell, so without it a 10 MB workbook could need ~750 MB
 *    against a 128 MB worker (the measurement that drove {@see \App\Modules\Crm\Services\Import\XlsConverter}).
 *    With it, memory is flat at ~6,000 cells no matter how large the file is.
 * 3. {@see MAX_SCAN_CELLS} — the filter bounds MEMORY but not TIME: the reader still streams the
 *    whole sheet to find the window. `listWorksheetInfo()` reports the declared dimensions WITHOUT
 *    materializing a workbook, so a pathological sheet is rejected before it is ever loaded.
 * 4. Scoped `memory_limit` / `max_execution_time`, both restored in `finally` — the same discipline,
 *    and the same reasoning about CLI vs web, as XlsConverter.
 *
 * ── FORMULAS ARE NEVER EVALUATED ─────────────────────────────────────────────────────────────
 * {@see stringify()} reads `getOldCalculatedValue()` — the result the authoring application cached
 * inside the file — and never `getCalculatedValue()`, which would run PhpSpreadsheet's calculation
 * engine over expressions supplied by whoever uploaded the file. That is a code path an untrusted
 * document must not be able to reach.
 */
class SpreadsheetPreviewReader
{
    /** Rows of the first sheet returned. Beyond this the viewer says so and points at Download. */
    public const MAX_ROWS = 200;

    /** Columns returned (A..AD). */
    public const MAX_COLUMNS = 30;

    /**
     * Declared cells above which the sheet is refused rather than scanned.
     *
     * A ceiling on TIME, not memory — bound 3 above. 1M cells is ~33,000 rows x 30 columns, far past
     * any spreadsheet a BD attaches to a lead, so this rejects only pathological or hostile files.
     */
    public const MAX_SCAN_CELLS = 1_000_000;

    /** The only readers permitted to touch the bytes. Order is the identification order. */
    private const FORMATS = ['Xlsx', 'Xls'];

    /** Raised for the duration of one read, then restored — never a global change. */
    private const READ_MEMORY_LIMIT = '256M';

    /** Web-request reads stay well inside `max_execution_time` (30s in production). */
    private const READ_TIME_LIMIT = 20;

    /** A single cell cannot dominate the payload; Excel allows ~32k characters in one. */
    private const MAX_CELL_LENGTH = 500;

    /**
     * @param  string  $absolutePath  a path on the PRIVATE disk, already authorized by the caller
     *
     * @throws UnreadableSpreadsheetException the only exception this method throws
     */
    public function read(string $absolutePath): SpreadsheetPreview
    {
        $format = $this->identifyFormat($absolutePath);

        // Scoped, not global: a global raise would multiply across every worker. Always restored.
        $previousMemory = ini_get('memory_limit');
        ini_set('memory_limit', self::READ_MEMORY_LIMIT);

        // WEB REQUESTS ONLY. `max_execution_time` is 0 under CLI (queue workers, tests); clamping
        // there would shorten everything the process ran afterwards, since the limit is per-process.
        $previousTime = (int) ini_get('max_execution_time');
        $boundTime = $previousTime > 0 && function_exists('set_time_limit');
        if ($boundTime) {
            @set_time_limit(self::READ_TIME_LIMIT);
        }

        $spreadsheet = null;

        try {
            $reader = IOFactory::createReader($format);
            // Styles, drawings and formatting are cost without benefit — the window is text.
            $reader->setReadDataOnly(true);

            $info = $this->worksheetInfo($reader, $absolutePath);
            $first = $info[0];
            $totalRows = max(0, (int) ($first['totalRows'] ?? 0));
            $totalColumns = max(0, (int) ($first['totalColumns'] ?? 0));

            if ($totalRows * $totalColumns > self::MAX_SCAN_CELLS) {
                throw new UnreadableSpreadsheetException(UnreadableSpreadsheetException::TOO_LARGE);
            }

            $sheetName = (string) ($first['worksheetName'] ?? '');
            if ($sheetName !== '') {
                // Sheets 2..n are never parsed at all, not merely discarded after parsing.
                $reader->setLoadSheetsOnly($sheetName);
            }
            $reader->setReadFilter(new BoundedWindowFilter(self::MAX_ROWS, self::MAX_COLUMNS));

            $spreadsheet = $reader->load($absolutePath);
            $sheet = $spreadsheet->getSheet(0);

            $rowCount = min($totalRows, self::MAX_ROWS);
            $columnCount = min(max($totalColumns, 1), self::MAX_COLUMNS);

            $rows = [];
            for ($row = 1; $row <= $rowCount; $row++) {
                $line = [];
                for ($column = 1; $column <= $columnCount; $column++) {
                    // cellExists() first: getCell() CREATES a cell when one is absent, which would
                    // grow the sheet by up to 6,000 empty objects while reading it.
                    $line[] = $sheet->cellExists([$column, $row])
                        ? $this->stringify($sheet->getCell([$column, $row]))
                        : '';
                }
                $rows[] = $line;
            }

            return new SpreadsheetPreview(
                sheetName: $sheetName !== '' ? $sheetName : 'Sheet1',
                sheetCount: count($info),
                rows: $rows,
                totalRows: $totalRows,
                totalColumns: $totalColumns,
            );
        } catch (UnreadableSpreadsheetException $e) {
            throw $e;
        } catch (Throwable $e) {
            // Corrupt archive, password-protected workbook, parser fatal — one safe message, and the
            // original kept only as `previous` so it reaches the log and never the client.
            throw new UnreadableSpreadsheetException(UnreadableSpreadsheetException::UNREADABLE, 0, $e);
        } finally {
            // Release the object graph immediately rather than at end of request.
            if ($spreadsheet !== null) {
                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet);
            }

            ini_set('memory_limit', $previousMemory === false ? '128M' : $previousMemory);

            if ($boundTime) {
                @set_time_limit($previousTime);
            }
        }
    }

    /**
     * The reader that actually matches the BYTES, restricted to the allowlist.
     *
     * `identify()` throws when nothing matches, which is the desired outcome for a mislabelled file.
     *
     * @throws UnreadableSpreadsheetException
     */
    private function identifyFormat(string $absolutePath): string
    {
        try {
            $format = IOFactory::identify($absolutePath, self::FORMATS);
        } catch (Throwable $e) {
            throw new UnreadableSpreadsheetException(UnreadableSpreadsheetException::UNREADABLE, 0, $e);
        }

        // Belt and braces: `identify()` is already restricted, so this can only fire if a future
        // PhpSpreadsheet release widens what a restricted identify may return.
        if (! in_array($format, self::FORMATS, true)) {
            throw new UnreadableSpreadsheetException(UnreadableSpreadsheetException::UNREADABLE);
        }

        return $format;
    }

    /**
     * Declared sheet dimensions, read WITHOUT building a workbook — bound 3.
     *
     * @return non-empty-list<array<string, mixed>>
     *
     * @throws UnreadableSpreadsheetException
     */
    private function worksheetInfo(IReader $reader, string $absolutePath): array
    {
        try {
            $info = array_values($reader->listWorksheetInfo($absolutePath));
        } catch (Throwable $e) {
            throw new UnreadableSpreadsheetException(UnreadableSpreadsheetException::UNREADABLE, 0, $e);
        }

        if ($info === []) {
            throw new UnreadableSpreadsheetException(UnreadableSpreadsheetException::UNREADABLE);
        }

        return $info;
    }

    /**
     * One cell as a display string.
     *
     * Never calls `getCalculatedValue()` — see the class docblock. A formula cell falls back to the
     * value the authoring application cached in the file, and to an empty string when it cached none.
     */
    private function stringify(Cell $cell): string
    {
        $value = $cell->getValue();

        if (is_string($value) && str_starts_with($value, '=')) {
            $value = $cell->getOldCalculatedValue();
        }

        return $this->scalarToString($value);
    }

    /** Anything PhpSpreadsheet can hand back, flattened to text the client renders verbatim. */
    private function scalarToString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i');
        }

        // RichText survives `setReadDataOnly(true)` in some paths; take its text, not its markup.
        if (is_object($value) && method_exists($value, 'getPlainText')) {
            $value = $value->getPlainText();
        } elseif (! is_scalar($value)) {
            return '';
        }

        $string = (string) $value;

        return mb_strlen($string) > self::MAX_CELL_LENGTH
            ? mb_substr($string, 0, self::MAX_CELL_LENGTH).'…'
            : $string;
    }
}
