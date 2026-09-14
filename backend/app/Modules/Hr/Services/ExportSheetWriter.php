<?php

namespace App\Modules\Hr\Services;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderName;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\BorderStyle;
use OpenSpout\Common\Entity\Style\BorderWidth;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * WHAT AN EXPORTED SHEET LOOKS LIKE — the one definition of it.
 *
 * Letterhead, summary cards, a ruled table, a total that foots it. This began life inside
 * PayrollXlsxWriter as the payroll breakdown's own styling; it lives here because every other
 * export is now asked to look like that one, and a design copied per report is a design that
 * drifts per report. The company name and address come from Company → Settings via
 * CompanyProfileService::letterhead(), so the workbooks, the payslip PDF and the report PDF all
 * print the same letterhead or none of them do.
 *
 * Nothing here knows what a payslip or a leave liability is. It takes a table and dresses it.
 */
final class ExportSheetWriter
{
    public const INK = '171717';

    public const MUTED = '777777';

    public const RULE = 'D9D9D9';

    public const CARD = 'F7F7F7';

    public const FACE = 'Arial';

    /** Earnings and net: the figure reads as money. */
    public const MONEY = '"₱"#,##0.00';

    /** Charges: stored negative, shown as (1,809.50) — the accounting convention the sheet uses. */
    public const CHARGE = '#,##0.00;(#,##0.00)';

    /**
     * Rows of preamble above the column headers, with and without the summary cards.
     *
     * Name · title · address · blank [· card labels · card values · blank · blank] → headers.
     * A report with nothing worth putting on a card gets the short version rather than a band
     * of empty boxes, so the count is derived rather than fixed.
     */
    private const PREAMBLE_WITH_CARDS = 8;

    private const PREAMBLE_WITHOUT_CARDS = 4;

    /**
     * Write one designed sheet into an already-open workbook.
     *
     * @param  array{title: string,
     *               kpis?: list<array{label: string, value: float|int|string, type: string}>,
     *               columns: list<array{label: string, type: string, width: float}>,
     *               rows: iterable<int, list<float|int|string|null>>,
     *               totals?: list<float|int|string|null>|null}  $sheet
     * @param  array{name: string, address: string, logo: ?string}  $letterhead
     */
    public static function write(Writer $writer, string $sheetName, array $sheet, array $letterhead): void
    {
        $page = $writer->getCurrentSheet();
        $page->setName($sheetName);

        $columns = $sheet['columns'];
        $lastColumn = count($columns) - 1;
        $kpis = $sheet['kpis'] ?? [];

        foreach ($columns as $i => $column) {
            $page->setColumnWidth((float) $column['width'], $i + 1);
        }

        self::letterhead($writer, $sheet['title'], $letterhead);

        if ($kpis !== []) {
            self::cards($writer, $kpis);
        }

        // --- the table ------------------------------------------------------------------------
        $headerLeft = self::text(8, 'FFFFFF', bold: true)->withBackgroundColor(self::INK);
        $headerRight = $headerLeft->withCellAlignment(CellAlignment::RIGHT);
        $header = [];

        foreach ($columns as $column) {
            $header[] = new StringCell($column['label'], 'text' === $column['type'] ? $headerLeft : $headerRight);
        }

        $writer->addRow(new Row($header, 18.0));

        $bodyStyles = self::bodyStyles();
        $written = 0;

        foreach ($sheet['rows'] as $row) {
            $writer->addRow(new Row(self::bodyCells($columns, $row, $bodyStyles)));
            $written++;
        }

        if (! empty($sheet['totals'])) {
            // A blank line, then the total — the gap is what stops a filter or a stray sort from
            // dragging the total row up into the data it is summarising.
            $writer->addRow(new Row([]));
            $writer->addRow(new Row(self::bodyCells($columns, $sheet['totals'], self::totalStyles())));
        }

        $headerRow = ($kpis !== [] ? self::PREAMBLE_WITH_CARDS : self::PREAMBLE_WITHOUT_CARDS) + 1;
        $lastDataRow = $headerRow + $written;

        $page->setSheetView(new SheetView(freezeRow: $headerRow + 1));

        // Only over the data itself: including the total row would let a filter hide it.
        if ($lastDataRow > $headerRow) {
            $page->setAutoFilter(new AutoFilter(0, $headerRow, $lastColumn, $lastDataRow));
        }
    }

    /**
     * The company, then what this file is. Same name and address the payslip prints — both read
     * Company → Settings, so a workbook and the PDF can no longer drift apart.
     *
     * @param  array{name: string, address: string, logo: ?string}  $letterhead
     */
    private static function letterhead(Writer $writer, string $title, array $letterhead): void
    {
        $writer->addRow(new Row([new StringCell($letterhead['name'], self::text(16, self::INK, bold: true))], 22.0));
        $writer->addRow(new Row([new StringCell($title, self::text(10, self::MUTED))]));
        $writer->addRow(new Row([new StringCell($letterhead['address'], self::text(8, self::MUTED))]));
        $writer->addRow(new Row([]));
    }

    /**
     * The summary band. Each card spans three columns so the numbers have room to breathe;
     * the first and last carry the outer edge so the band reads as one strip.
     *
     * @param  list<array{label: string, value: float|int|string, type: string}>  $kpis
     */
    private static function cards(Writer $writer, array $kpis): void
    {
        $labels = [];
        $values = [];

        foreach ($kpis as $i => $kpi) {
            $first = 0 === $i;
            $last = count($kpis) - 1 === $i;

            $labels[] = new StringCell($kpi['label'], self::card(7, self::MUTED, bold: true, left: $first));

            $style = self::card(12, self::INK, bold: true, left: $first, format: match ($kpi['type']) {
                'money' => self::MONEY,
                'charge' => self::CHARGE,
                'count' => '#,##0',
                default => null,
            });

            // A card whose figure is not a number at all ("3.2 yrs") stays the text it already
            // was, rather than being coerced to 3.2 and losing what it measured.
            $values[] = 'text' === $kpi['type']
                ? new StringCell((string) $kpi['value'], $style)
                : Cell::fromValue(
                    'charge' === $kpi['type'] ? -abs((float) $kpi['value']) : $kpi['value'],
                    $style,
                );

            foreach ([1, 2] as $offset) {
                $edge = $last && 2 === $offset;
                $labels[] = Cell::fromValue('', self::card(7, self::MUTED, right: $edge));
                $values[] = Cell::fromValue('', self::card(12, self::INK, right: $edge));
            }
        }

        $writer->addRow(new Row($labels, 16.0));
        $writer->addRow(new Row($values, 20.0));
        $writer->addRow(new Row([]));
        $writer->addRow(new Row([]));
    }

    /**
     * One table row, styled per its column's type.
     *
     * @param  list<array{label: string, type: string, width: float}>  $columns
     * @param  list<float|int|string|null>  $values
     * @param  array<string, Style>  $styles
     * @return list<Cell>
     */
    public static function bodyCells(array $columns, array $values, array $styles): array
    {
        $cells = [];

        foreach ($columns as $i => $column) {
            $type = $column['type'];
            $value = $values[$i] ?? null;
            $style = $styles[$type] ?? $styles['text'];

            if ('charge' === $type) {
                // `-abs(0.0)` is negative zero, which Excel prints as -0.00 in a format that shows
                // a sign. A charge of nothing is nothing.
                $value = 0.0 === (float) $value ? 0.0 : -abs((float) $value);
            }

            $cells[] = in_array($type, ['text', 'status'], true)
                ? new StringCell((string) $value, $style)
                : Cell::fromValue($value, $style);
        }

        return $cells;
    }

    /**
     * The body row's looks. A hairline under every row is what keeps 26 columns readable
     * across — without it the eye loses the line somewhere around the contributions.
     *
     * @return array<string, Style>
     */
    public static function bodyStyles(): array
    {
        $rule = new Border(new BorderPart(BorderName::BOTTOM, self::RULE, BorderWidth::THIN, BorderStyle::SOLID));

        $text = self::text(8, self::INK)->withBorder($rule);
        $money = $text->withCellAlignment(CellAlignment::RIGHT)->withFormat(self::MONEY);

        return [
            'text' => $text,
            'count' => $text->withCellAlignment(CellAlignment::RIGHT)->withFormat('#,##0'),
            'number' => $text->withCellAlignment(CellAlignment::RIGHT)->withFormat('#,##0.0'),
            'money' => $money,
            'charge' => $money->withFormat(self::CHARGE),
            'status' => self::text(7, self::MUTED)->withBorder($rule),
        ];
    }

    /**
     * The total row: a medium rule above it, in ink rather than grey, so it reads as the line the
     * table foots to and not as one more employee.
     *
     * @return array<string, Style>
     */
    public static function totalStyles(): array
    {
        $rule = new Border(new BorderPart(BorderName::TOP, self::INK, BorderWidth::MEDIUM, BorderStyle::SOLID));

        $text = self::text(8, self::INK, bold: true)->withBorder($rule);
        $money = $text->withCellAlignment(CellAlignment::RIGHT)->withFormat(self::MONEY);

        return [
            'text' => $text,
            'count' => $text->withCellAlignment(CellAlignment::RIGHT)->withFormat('#,##0'),
            'number' => $text->withCellAlignment(CellAlignment::RIGHT)->withFormat('#,##0.0'),
            'money' => $money,
            'charge' => $money->withFormat(self::CHARGE),
            'status' => self::text(7, self::MUTED, bold: true)->withBorder($rule),
        ];
    }

    public static function text(int $size, string $color, bool $bold = false): Style
    {
        return (new Style())
            ->withFontName(self::FACE)
            ->withFontSize($size)
            ->withFontColor($color)
            ->withFontBold($bold);
    }

    /** A cell of one of the summary cards: filled, ruled top and bottom, open at the sides. */
    private static function card(
        int $size,
        string $color,
        bool $bold = false,
        bool $left = false,
        bool $right = false,
        ?string $format = null,
    ): Style {
        $parts = [
            new BorderPart(BorderName::TOP, self::RULE, BorderWidth::THIN, BorderStyle::SOLID),
            new BorderPart(BorderName::BOTTOM, self::RULE, BorderWidth::THIN, BorderStyle::SOLID),
        ];

        if ($left) {
            $parts[] = new BorderPart(BorderName::LEFT, self::RULE, BorderWidth::THIN, BorderStyle::SOLID);
        }

        if ($right) {
            $parts[] = new BorderPart(BorderName::RIGHT, self::RULE, BorderWidth::THIN, BorderStyle::SOLID);
        }

        $style = self::text($size, $color, $bold)
            ->withBackgroundColor(self::CARD)
            ->withBorder(new Border(...$parts));

        return null === $format ? $style : $style->withFormat($format);
    }
}
