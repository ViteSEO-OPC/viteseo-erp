<?php

namespace App\Modules\Hr\Services;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Reads an .xlsx into a grid of strings. Also accepts CSV, so HR can hand over whichever file the
 * agency published.
 *
 * WHY NO LIBRARY. An .xlsx is a zip of XML, and the two extensions needed to open one (`zip`,
 * `simplexml`) are already required by this app. The alternative is a heavyweight dependency for
 * one screen that reads one government table — and the CLAUDE.md rule is not to add libraries
 * without asking. What is deliberately NOT supported: formulas (the cached value is read instead),
 * the older binary .xls, and styling. A published contribution schedule has none of those.
 *
 * Everything comes back as a STRING, exactly as the cell reads. Parsing "5,250 - 5,749.99" into a
 * number is the importer's job, not the reader's — the reader must not guess what a column means.
 */
class SpreadsheetReader
{
    /** Refuse anything larger; a contribution schedule is a few dozen rows. */
    private const MAX_ROWS = 2000;

    /**
     * The first worksheet as rows of cells, keyed by column letter (`['A' => 'BELOW 5,250', …]`).
     * Blank cells are absent rather than empty, so a row of gaps is an empty array.
     *
     * @return array<int, array<string, string>>
     */
    public function read(string $path, string $originalName = ''): array
    {
        $extension = strtolower(pathinfo($originalName ?: $path, PATHINFO_EXTENSION));

        return $extension === 'csv' ? $this->readCsv($path) : $this->readXlsx($path);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function readXlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('That file could not be opened as a spreadsheet.');
        }

        try {
            $sheetPath = $this->firstSheetPath($zip);
            $sheetXml = $zip->getFromName($sheetPath);
            if ($sheetXml === false) {
                throw new RuntimeException('The spreadsheet has no readable worksheet.');
            }

            $shared = $this->sharedStrings($zip);
            $sheet = new SimpleXMLElement($sheetXml);

            $rows = [];
            foreach ($sheet->sheetData->row as $row) {
                if (count($rows) >= self::MAX_ROWS) {
                    break;
                }

                $cells = [];
                foreach ($row->c as $cell) {
                    $reference = (string) $cell['r'];
                    $column = preg_replace('/\d+/', '', $reference);
                    $value = $this->cellValue($cell, $shared);
                    if ($value !== '') {
                        $cells[$column] = $value;
                    }
                }
                $rows[] = $cells;
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    /**
     * The worksheet the workbook lists FIRST, resolved through the relationships file rather than
     * assumed to be `sheet1.xml`. Excel keeps the original file name when sheets are reordered or
     * deleted, so the first sheet is often not sheet1 — reading the wrong one silently imports the
     * wrong table.
     */
    private function firstSheetPath(ZipArchive $zip): string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbook !== false && $rels !== false) {
            $wb = new SimpleXMLElement($workbook);
            $first = $wb->sheets->sheet[0] ?? null;
            $relId = $first === null
                ? null
                : (string) ($first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');

            if ($relId) {
                foreach ((new SimpleXMLElement($rels))->Relationship as $rel) {
                    if ((string) $rel['Id'] === $relId) {
                        $target = ltrim((string) $rel['Target'], '/');

                        return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                    }
                }
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    /**
     * The shared-string table. Most text in an .xlsx is stored once here and referenced by index,
     * so without this every label reads as a number.
     *
     * @return array<int, string>
     */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $strings = [];
        foreach ((new SimpleXMLElement($xml))->si as $item) {
            // A single string is <t>; a formatted one is split across <r><t>…</t></r> runs, which
            // have to be concatenated or "Range of Compensation" arrives as "Range".
            $text = '';
            foreach ($item->xpath('.//*[local-name()="t"]') ?: [] as $t) {
                $text .= (string) $t;
            }
            $strings[] = $text;
        }

        return $strings;
    }

    /** @param array<int, string> $shared */
    private function cellValue(SimpleXMLElement $cell, array $shared): string
    {
        $type = (string) $cell['t'];

        // Inline strings live in <is>, not <v> — rare, but Google Sheets exports them.
        if ($type === 'inlineStr') {
            $text = '';
            foreach ($cell->xpath('.//*[local-name()="t"]') ?: [] as $t) {
                $text .= (string) $t;
            }

            return trim($text);
        }

        if (! isset($cell->v)) {
            return '';
        }

        $raw = (string) $cell->v;

        // `s` = shared-string index. An out-of-range index means a malformed file; treat it as
        // blank rather than fabricating a value.
        if ($type === 's') {
            return trim($shared[(int) $raw] ?? '');
        }

        return trim($raw);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException('That file could not be opened.');
        }

        try {
            $rows = [];
            while (($line = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if (count($rows) >= self::MAX_ROWS) {
                    break;
                }

                $cells = [];
                foreach ($line as $i => $value) {
                    $value = trim((string) $value);
                    if ($value !== '') {
                        $cells[self::columnLetter($i)] = $value;
                    }
                }
                $rows[] = $cells;
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /** 0 → A, 25 → Z, 26 → AA — so a CSV and an .xlsx address their columns the same way. */
    public static function columnLetter(int $index): string
    {
        $letter = '';
        for ($n = $index; $n >= 0; $n = intdiv($n, 26) - 1) {
            $letter = chr(65 + $n % 26) . $letter;
        }

        return $letter;
    }
}
