<?php

namespace App\Modules\Hr\Services;

/**
 * Writes CSV rows with spreadsheet formula injection neutralised (FIN-019).
 *
 * THE PROBLEM. Excel, LibreOffice and Sheets treat a cell whose first character is `=`, `+`, `-`
 * or `@` as a FORMULA rather than text. Several columns in this module's exports are free text an
 * employee controls — their own name, a department name, an audit description, concern text. An
 * employee named `=cmd|'/c calc'!A1` produces a payroll CSV that executes on open, and the machine
 * it opens on is finance's, which is exactly the machine worth attacking. The file is not
 * malformed and nothing warns: the export looks perfectly normal right up to the moment it runs.
 *
 * THE FIX is the accepted one (OWASP): prefix a dangerous leading character with a single quote,
 * which spreadsheets read as "the rest of this cell is literal text" and do not display. Tab and
 * carriage return are included because they are stripped by some parsers before the formula check,
 * putting the trigger character back at the front.
 *
 * WHY A SHARED CLASS. `PayrollService::runCsv()` and `ReportService::buildCsv()` each opened their
 * own handle and called `fputcsv()` directly. Two writers means the next export added is a coin
 * flip on whether it is safe — the point of this class is that there is one place to be right.
 */
final class CsvWriter
{
    /**
     * Leading characters a spreadsheet may interpret as the start of a formula.
     *
     * `-` is included even though a negative number is a legitimate cell value; the prefix makes
     * it text, so any column that must stay numeric should be formatted before it gets here.
     */
    private const RISKY_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Render rows as a CSV string, with a UTF-8 BOM so Excel reads ₱ and é correctly.
     *
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    public static function render(iterable $rows, bool $bom = true): string
    {
        $out = fopen('php://temp', 'r+');

        if ($bom) {
            fwrite($out, "\xEF\xBB\xBF");
        }

        foreach ($rows as $row) {
            // Explicit empty escape character: PHP's default backslash escaping is deprecated
            // from 8.4 and is not what a spreadsheet expects anyway. Both callers already did
            // this; keeping it here is what let them stop caring.
            fputcsv($out, array_map([self::class, 'neutralise'], $row), ',', '"', '');
        }

        rewind($out);

        return (string) stream_get_contents($out);
    }

    /**
     * Make one cell safe to open in a spreadsheet.
     *
     * Non-strings pass through untouched: a float or an int cannot carry a formula, and casting
     * them here would turn every amount into text and break the totals finance opens this for.
     */
    public static function neutralise(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        return in_array($value[0], self::RISKY_PREFIXES, true) ? "'" . $value : $value;
    }
}
