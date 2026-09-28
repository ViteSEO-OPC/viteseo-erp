<?php

namespace App\Modules\Crm\Services\Attachments;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * Admits only the top-left window of a sheet, so the reader never materializes the rest.
 *
 * This is the memory bound that makes previewing an untrusted workbook safe: PhpSpreadsheet asks
 * before building each Cell object, so a sheet with a million populated cells still costs the same
 * ~6,000 objects as a small one. Filtering AFTER the load — reading everything and slicing — would
 * be the exact failure this exists to prevent.
 *
 * Column addresses arrive as letters ("A", "AD", "ZZ"), so they are converted rather than compared
 * as strings: "AA" sorts before "B" alphabetically but is column 27.
 */
final readonly class BoundedWindowFilter implements IReadFilter
{
    public function __construct(
        private int $maxRows,
        private int $maxColumns,
    ) {
    }

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        if ($row < 1 || $row > $this->maxRows) {
            return false;
        }

        return Coordinate::columnIndexFromString($columnAddress) <= $this->maxColumns;
    }
}
