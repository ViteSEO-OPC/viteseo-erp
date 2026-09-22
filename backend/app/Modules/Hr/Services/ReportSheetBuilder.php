<?php

namespace App\Modules\Hr\Services;

/**
 * A report, as the sheet design wants it.
 *
 * The reports already know their own data — the summary metrics on screen, the table under
 * them. This does not change any of it; it works out what each column IS so the design can dress
 * it, which is the one thing a CSV never had to answer.
 *
 * The reports format their own values for the browser ("₱412,880", "12.5"), so the figures arrive
 * as strings. A string in a spreadsheet is not a number: it will not sum, it sorts as text, and
 * the ₱ in front of it is a character rather than a currency. So a money column is read back to
 * 412880.0 and handed a "₱"#,##0.00 format — the cell still READS ₱412,880.00, and now it also
 * behaves like the amount it always was.
 */
final class ReportSheetBuilder
{
    /** Anything wider than this wraps badly; anything narrower crops a header. */
    private const MIN_WIDTH = 10.0;

    private const MAX_WIDTH = 34.0;

    /**
     * @param  array<string, mixed>  $report  as ReportService::get() returns it
     * @return array{title: string, kpis: list<array{label: string, value: float|int|string, type: string}>,
     *               columns: list<array{label: string, type: string, width: float}>,
     *               rows: list<list<float|int|string|null>>,
     *               totals: list<float|int|string|null>|null}
     */
    public static function build(array $report, string $title): array
    {
        [$labels, $rows] = self::tableOf($report);

        $types = [];
        foreach ($labels as $i => $label) {
            $types[$i] = self::typeOf(array_map(fn (array $row) => $row[$i] ?? null, $rows));
        }

        $columns = [];
        foreach ($labels as $i => $label) {
            $columns[] = [
                'label' => $label,
                'type' => $types[$i],
                'width' => self::widthOf($label, array_map(fn (array $row) => $row[$i] ?? null, $rows)),
            ];
        }

        // Values converted to what their column turned out to be, once, here — so the writer
        // only ever deals in numbers for numeric columns and strings for prose.
        $cast = [];
        foreach ($rows as $row) {
            $out = [];
            foreach ($labels as $i => $label) {
                $value = $row[$i] ?? null;
                $out[] = 'text' === $types[$i] ? (string) ($value ?? '') : self::number($value);
            }
            $cast[] = $out;
        }

        return [
            'title' => $title,
            'kpis' => self::kpis($report),
            'columns' => $columns,
            'rows' => $cast,
            'totals' => self::totals($columns, $cast, $rows),
        ];
    }

    /**
     * The table this report carries: its own if it has one, its audit entries if it is the audit
     * trail, and otherwise its summary metrics laid out as a two-column table — exactly the three
     * cases the CSV export has always covered, in the same order.
     *
     * @param  array<string, mixed>  $report
     * @return array{0: list<string>, 1: list<list<string|null>>}
     */
    private static function tableOf(array $report): array
    {
        if (! empty($report['entries'])) {
            return [
                ['Timestamp', 'User', 'Module', 'Action', 'Details'],
                array_map(
                    fn (array $e) => [$e['timestamp'], $e['user'], $e['module'], $e['action'], $e['details']],
                    $report['entries'],
                ),
            ];
        }

        if (! empty($report['table'])) {
            return [$report['table']['columns'], $report['table']['rows']];
        }

        return [
            ['Metric', 'Value'],
            array_map(fn (array $s) => [$s['label'], $s['value']], $report['summary'] ?? []),
        ];
    }

    /**
     * The summary band — the same metrics the report shows above its table on screen.
     *
     * A metric that is not purely a number ("3.2 yrs", "14 of 22") stays text: coercing it would
     * print 3.2 and throw away what it was measuring.
     *
     * @param  array<string, mixed>  $report
     * @return list<array{label: string, value: float|int|string, type: string}>
     */
    private static function kpis(array $report): array
    {
        $kpis = [];

        // Four is what the band was designed around and what fits the width of a table.
        foreach (array_slice($report['summary'] ?? [], 0, 4) as $metric) {
            $value = (string) $metric['value'];
            $type = self::typeOf([$value]);

            $kpis[] = [
                'label' => mb_strtoupper($metric['label']),
                'value' => 'text' === $type ? $value : self::number($value),
                'type' => $type,
            ];
        }

        return $kpis;
    }

    /**
     * The line the table foots to, or null when the table already carries its own.
     *
     * The payroll register interleaves a subtotal after each department; summing every row there
     * would count those twice and print a total half again too big. A report that already totals
     * itself keeps its own arithmetic and gets no extra row — a wrong total is worse than none.
     *
     * @param  list<array{label: string, type: string, width: float}>  $columns
     * @param  list<list<float|int|string|null>>  $cast
     * @param  list<list<string|null>>  $raw
     * @return list<float|int|string|null>|null
     */
    private static function totals(array $columns, array $cast, array $raw): ?array
    {
        $numeric = array_keys(array_filter($columns, fn (array $c) => 'text' !== $c['type']));
        if ($numeric === []) {
            return null; // nothing to add up — the audit trail, for one
        }

        foreach ($raw as $row) {
            if (preg_match('/\b(total|subtotal)\b/i', (string) ($row[0] ?? ''))) {
                return null;
            }
        }

        $totals = [];
        foreach ($columns as $i => $column) {
            if ('text' === $column['type']) {
                $totals[] = 0 === $i ? 'TOTAL' : '';

                continue;
            }
            $totals[] = round(array_sum(array_map(fn (array $row) => (float) $row[$i], $cast)), 2);
        }

        // A first column that is itself numeric leaves the label nowhere to go; the band above
        // already names the report, so the row simply foots without one.
        return $totals;
    }

    /**
     * What a column holds, judged by every value in it. One stray word makes the column text —
     * a column that is money in nine rows and "—" in the tenth cannot be summed honestly.
     *
     * @param  list<string|null>  $values
     */
    private static function typeOf(array $values): string
    {
        $seen = false;
        $money = true;
        $whole = true;

        foreach ($values as $value) {
            $value = trim((string) ($value ?? ''));
            if ('' === $value) {
                continue; // a blank cell decides nothing
            }

            $bare = self::strip($value);
            if (! preg_match('/^-?\d+(\.\d+)?$/', $bare)) {
                return 'text';
            }

            $seen = true;
            $money = $money && str_contains($value, '₱');
            $whole = $whole && ! str_contains($bare, '.');
        }

        if (! $seen) {
            return 'text';
        }

        return $money ? 'money' : ($whole ? 'count' : 'number');
    }

    /** "₱412,880.00" → 412880.0. */
    private static function number(mixed $value): float
    {
        $bare = self::strip((string) ($value ?? ''));

        return is_numeric($bare) ? (float) $bare : 0.0;
    }

    /** Everything a formatter added and arithmetic does not want: the sign stays. */
    private static function strip(string $value): string
    {
        return str_replace(['₱', ',', ' ', "\u{00a0}"], '', trim($value));
    }

    /**
     * Wide enough for the longest thing in the column, within reason. Sampled rather than
     * measured across every row: a hundred thousand audit entries do not change the answer.
     *
     * @param  list<string|null>  $values
     */
    private static function widthOf(string $label, array $values): float
    {
        $longest = mb_strlen($label);

        foreach (array_slice($values, 0, 200) as $value) {
            $longest = max($longest, mb_strlen(trim((string) ($value ?? ''))));
        }

        return min(self::MAX_WIDTH, max(self::MIN_WIDTH, $longest + 2.0));
    }
}
