<?php

namespace App\Modules\Crm\Services\Attachments;

/**
 * The bounded window of a workbook that the Files & Downloads viewer renders.
 *
 * Deliberately a value object of PLAIN STRINGS rather than a PhpSpreadsheet object graph or a chunk
 * of generated HTML. Strings are what React can render as text nodes, so no cell content can ever
 * become markup; and detaching from the library here means the Spreadsheet is disconnected and freed
 * before the response is serialized.
 *
 * `totalRows` / `totalColumns` are the sheet's REAL dimensions, not the window's — the viewer needs
 * them to say honestly how much it is not showing.
 */
final readonly class SpreadsheetPreview
{
    /**
     * @param  list<list<string>>  $rows  the window, row-major, already truncated and stringified
     */
    public function __construct(
        public string $sheetName,
        public int $sheetCount,
        public array $rows,
        public int $totalRows,
        public int $totalColumns,
    ) {
    }

    /** True when the sheet extends beyond the window in either direction. */
    public function isTruncated(): bool
    {
        return $this->totalRows > count($this->rows)
            || $this->totalColumns > SpreadsheetPreviewReader::MAX_COLUMNS;
    }
}
