<?php

namespace App\Modules\Hr\Services;

use Symfony\Component\HttpFoundation\StreamedResponse;

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
     * The same CSV, written straight to the response as each row arrives.
     *
     * `render()` builds the whole file in memory before anything is sent, which is fine for a
     * report bounded by headcount and fatal for one that is not: the audit trail grows forever,
     * and an export of it materialised every row as an Eloquent model AND the finished string.
     * Streaming holds one row at a time, so peak memory stops tracking the table's size.
     *
     * `$rows` should be a generator for that to mean anything — handing this a fully-built array
     * streams the output but has already paid the memory.
     *
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    public static function stream(iterable $rows, string $filename, bool $bom = true): StreamedResponse
    {
        return new StreamedResponse(function () use ($rows, $bom) {
            $out = fopen('php://output', 'w');

            if ($bom) {
                fwrite($out, "\xEF\xBB\xBF");
            }

            // Deliberately no manual ob_flush()/flush() here. With no output buffering — the
            // normal case for a streamed response — writes to php://output already go straight
            // out, so flushing adds nothing. When a caller HAS buffered on purpose (a test
            // capturing the file), flushing shoves the rows past their buffer and they lose the
            // content they asked for.
            foreach ($rows as $row) {
                fputcsv($out, array_map([self::class, 'neutralise'], $row), ',', '"', '');
            }

            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
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
