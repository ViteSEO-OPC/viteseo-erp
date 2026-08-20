<?php

namespace App\Modules\Hr\Services;

/** Turns a published contribution schedule (the agency's own spreadsheet) into MSC bands. */
class ContributionScheduleImporter
{
    /**
     * Header keywords per column we look for, best match first. Matched against the header text
     * lowercased with punctuation stripped.
     *
     * @var array<string, array<int, string>>
     */
    private const HEADERS = [
        'range' => ['range of compensation', 'compensation range', 'monthly salary', 'range', 'compensation'],
        'msc' => ['msc total', 'total msc', 'monthly salary credit', 'msc'],
        'employer_add_on' => ['er ec', 'ec contribution', 'employer ec', 'ec'],
        'employee_total' => ['ee total', 'total ee', 'employee total', 'employee share'],
        'employer_total' => ['er total', 'total er', 'employer total', 'employer share'],
    ];

    /**
     * @param  array<int, array<string, string>>  $rows  the sheet, as SpreadsheetReader returns it
     * @param  array<string, string>  $mapping  HR's column overrides, e.g. ['msc' => 'D']
     * @return array<string, mixed>
     */
    public function parse(array $rows, array $mapping = []): array
    {
        $warnings = [];
        $headerRow = $this->findHeaderRow($rows);

        $detected = $headerRow === null
            ? []
            : $this->detectColumns($rows[$headerRow]);

        // HR's mapping always wins — the detection is a suggestion, and a schedule laid out in a
        // way we have not seen must still be importable without a code change. An EMPTY value is a
        // deliberate "this column is not in the file" and drops the field, so a table published
        // without (say) an EC column can be imported without one being invented for it.
        $columns = array_filter(array_merge($detected, $mapping), fn ($c) => $c !== '');

        if (! isset($columns['range'], $columns['msc'])) {
            return [
                'header_row' => $headerRow !== null ? $headerRow + 1 : null,
                'columns' => $columns,
                'bands' => [],
                'suggested' => [],
                'warnings' => [
                    'Could not tell which columns hold the salary range and the MSC. Point them out below and try again.',
                ],
                'title' => $this->title($rows),
            ];
        }

        $bands = $this->bands($rows, $columns, $headerRow ?? 0, $warnings);

        return [
            'header_row' => $headerRow !== null ? $headerRow + 1 : null,
            'columns' => $columns,
            'bands' => $bands,
            'suggested' => $this->suggest($bands, $warnings),
            'warnings' => $warnings,
            'title' => $this->title($rows),
        ];
    }

    /**
     * The first row that reads like a header. Published schedules carry a title and a circular
     * reference above the table, so the header is never row 1.
     *
     * @param  array<int, array<string, string>>  $rows
     */
    private function findHeaderRow(array $rows): ?int
    {
        foreach ($rows as $i => $cells) {
            $text = $this->normalise(implode(' ', $cells));
            // Both halves are required: a title line says "compensation" too, but only the header
            // also names the salary credit.
            if (str_contains($text, 'compensation') && str_contains($text, 'msc')) {
                return $i;
            }
            if (str_contains($text, 'range') && str_contains($text, 'msc')) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $header
     * @return array<string, string>
     */
    private function detectColumns(array $header): array
    {
        $found = [];
        $taken = [];

        // Longest keywords first so "msc total" beats "msc", and the column already claimed by a
        // more specific field is never re-used.
        foreach (self::HEADERS as $field => $keywords) {
            foreach ($keywords as $keyword) {
                foreach ($header as $column => $label) {
                    if (in_array($column, $taken, true)) {
                        continue;
                    }
                    if (str_contains($this->normalise($label), $keyword)) {
                        $found[$field] = $column;
                        $taken[] = $column;

                        continue 3;
                    }
                }
            }
        }

        return $found;
    }

    /**
     * One band per data row, lowest first.
     *
     * @param  array<int, array<string, string>>  $rows
     * @param  array<string, string>  $columns
     * @param  array<int, string>  $warnings
     * @return array<int, array<string, mixed>>
     */
    private function bands(array $rows, array $columns, int $headerRow, array &$warnings): array
    {
        $bands = [];
        $skipped = 0;

        foreach (array_slice($rows, $headerRow + 1, null, true) as $cells) {
            $rangeText = $cells[$columns['range']] ?? '';
            $msc = $this->number($cells[$columns['msc']] ?? '');

            // A row with no MSC is a footnote, a blank separator or a total line — not a band.
            if ($rangeText === '' || $msc === null) {
                $skipped++;

                continue;
            }

            $min = $this->lowerBound($rangeText);
            if ($min === null) {
                $warnings[] = "Could not read the salary range “{$rangeText}” — that row was skipped.";

                continue;
            }

            $bands[] = [
                'min_salary' => $min,
                'msc' => $msc,
                'employer_add_on' => isset($columns['employer_add_on'])
                    ? $this->number($cells[$columns['employer_add_on']] ?? '')
                    : null,
                // Kept only to check our arithmetic against the agency's own figures below.
                'published_employee' => isset($columns['employee_total'])
                    ? $this->number($cells[$columns['employee_total']] ?? '')
                    : null,
                'published_employer' => isset($columns['employer_total'])
                    ? $this->number($cells[$columns['employer_total']] ?? '')
                    : null,
                'range' => $rangeText,
            ];
        }

        usort($bands, fn ($a, $b) => $a['min_salary'] <=> $b['min_salary']);

        if ($bands !== [] && $bands[0]['min_salary'] > 0) {
            // Every salary has to land in a band. The published first row usually reads
            // "BELOW 5,250", which is 0 — if it did not parse that way, say so loudly.
            $warnings[] = sprintf(
                'The lowest band starts at %s, so anyone earning less would contribute nothing. It will be lowered to 0 on save.',
                number_format($bands[0]['min_salary'], 2),
            );
            $bands[0]['min_salary'] = 0.0;
        }

        if ($skipped > 0) {
            $warnings[] = "{$skipped} row(s) had no salary credit and were ignored (titles, notes, totals).";
        }

        return $bands;
    }

    /**
     * Rates and ceiling inferred from the table itself, plus a check that our arithmetic
     * reproduces the agency's printed amounts.
     *
     * @param  array<int, array<string, mixed>>  $bands
     * @param  array<int, string>  $warnings
     * @return array<string, mixed>
     */
    private function suggest(array $bands, array &$warnings): array
    {
        if ($bands === []) {
            return [];
        }

        $employeeRate = $this->modalRate($bands, 'published_employee');
        $employerRate = $this->modalRate($bands, 'published_employer');
        $top = end($bands);

        // The employer total includes the EC, which is not a percentage — take it off before
        // inferring the rate, or every employer rate reads slightly high.
        if ($employerRate !== null) {
            $employerRate = $this->modalRate($bands, 'published_employer', minusAddOn: true);
        }

        $mismatched = [];
        foreach ($bands as $band) {
            if ($employeeRate !== null && $band['published_employee'] !== null) {
                $expected = round($band['msc'] * $employeeRate, 2);
                if (abs($expected - $band['published_employee']) >= 0.01) {
                    $mismatched[] = $band['range'];
                }
            }
        }

        if ($mismatched !== []) {
            $warnings[] = sprintf(
                'Our calculation does not match the printed employee amount on %d band(s): %s. Check the rate before saving.',
                count($mismatched),
                implode(', ', array_slice($mismatched, 0, 3)) . (count($mismatched) > 3 ? '…' : ''),
            );
        }

        return array_filter([
            'basis' => 'bracket',
            'employee_rate' => $employeeRate,
            'employer_rate' => $employerRate,
            // The top band's MSC is the ceiling: every salary above it is charged on that figure.
            'salary_cap' => (float) $top['msc'],
            'band_count' => count($bands),
            'amounts_match' => $mismatched === [],
        ], fn ($v) => $v !== null);
    }

    /**
     * The rate the majority of bands agree on, as a decimal. A single odd row (a footnote that
     * parsed as a band, say) must not drag the rate off — hence the mode rather than an average.
     *
     * @param  array<int, array<string, mixed>>  $bands
     */
    private function modalRate(array $bands, string $key, bool $minusAddOn = false): ?float
    {
        $counts = [];
        foreach ($bands as $band) {
            $published = $band[$key];
            if ($published === null || $band['msc'] <= 0) {
                continue;
            }
            if ($minusAddOn) {
                $published -= (float) ($band['employer_add_on'] ?? 0);
            }
            // 5 decimals matches the column the rate is stored in.
            $rate = (string) round($published / $band['msc'], 5);
            $counts[$rate] = ($counts[$rate] ?? 0) + 1;
        }

        if ($counts === []) {
            return null;
        }

        arsort($counts);

        return (float) array_key_first($counts);
    }

    /** The lower bound of a printed range. */
    private function lowerBound(string $text): ?float
    {
        $normalised = $this->normalise($text);

        if (preg_match('/\b(below|under|less than)\b/', $normalised)) {
            return 0.0;
        }

        return $this->number($text);
    }

    /** The first number in a cell, tolerating thousands separators, currency and stray text. */
    private function number(string $text): ?float
    {
        // Strip separators only between digits: "5,250" is one number, but a lone "-" (how the
        // published table writes "not applicable") must stay unreadable rather than become 0.
        $cleaned = preg_replace('/(?<=\d),(?=\d)/', '', trim($text));

        return preg_match('/-?\d+(\.\d+)?/', (string) $cleaned, $m) ? (float) $m[0] : null;
    }

    /**
     * The title lines above the table — usually the circular number and its effective date, which
     * is exactly what HR needs to fill in "effective from" correctly.
     *
     * @param  array<int, array<string, string>>  $rows
     */
    private function title(array $rows): ?string
    {
        foreach (array_slice($rows, 0, 4) as $cells) {
            $text = trim(implode(' ', $cells));
            if ($text !== '' && preg_match('/circular|effective|schedule/i', $text)) {
                return $text;
            }
        }

        return null;
    }

    private function normalise(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', strtolower(preg_replace('/[^a-z0-9\s]/i', ' ', $text))));
    }
}
