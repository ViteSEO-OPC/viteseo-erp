<?php

namespace App\Modules\Hr\Services;

/** Writes CSV rows with spreadsheet formula injection neutralised. */
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
