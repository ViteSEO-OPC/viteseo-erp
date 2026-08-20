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
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/** THE PAYROLL BREAKDOWN AS A WORKBOOK — the styling half of the run export. */
final class PayrollXlsxWriter
{
    /** Matches the payslip PDF, which hardcodes the same name (payslip.blade.php). */
    public const COMPANY = 'VITE SEO DIGITAL ADVERTISING OPC';

    public const ADDRESS = 'Makati City, Metro Manila, Philippines';

    private const INK = '171717';

    private const MUTED = '777777';

    private const RULE = 'D9D9D9';

    private const CARD = 'F7F7F7';

    private const FACE = 'Arial';

    /** Earnings and net: the figure reads as money. */
    private const MONEY = '"₱"#,##0.00';

    /** Charges: stored negative, shown as (1,809.50) — the accounting convention the sheet uses. */
    private const CHARGE = '#,##0.00;(#,##0.00)';

    /** Row the column headers land on. Everything above it is the letterhead and the cards. */
    private const HEADER_ROW = 9;

    /**
     * Write the workbook to `$path`.
     *
     * @param  array{title: string,
     *               kpis: list<array{label: string, value: float|int, type: string}>,
     *               columns: list<array{label: string, type: string, width: float}>,
     *               rows: list<list<float|int|string|null>>,
     *               totals: list<float|int|string|null>}  $breakdown
     * @param  array{columns: list<string>, rows: list<list<float|int|string|null>>, text: list<int>}  $source
     */
    public static function write(string $path, array $breakdown, array $source): void
    {
        $writer = new Writer(new Options());
        $writer->openToFile($path);

        self::writeBreakdown($writer, $breakdown);
        self::writeSource($writer, $source);

        $writer->close();
    }

    /**
     * Sheet 1 — the styled table, the one this whole class exists for.
     *
     * @param  array<string, mixed>  $breakdown
     */
    private static function writeBreakdown(Writer $writer, array $breakdown): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Payroll Export');

        $columns = $breakdown['columns'];
        $lastColumn = count($columns) - 1;

        foreach ($columns as $i => $column) {
            $sheet->setColumnWidth((float) $column['width'], $i + 1);
        }

        // --- letterhead -----------------------------------------------------------------------
        $writer->addRow(new Row([new StringCell(self::COMPANY, self::text(16, self::INK, bold: true))], 22.0));
        $writer->addRow(new Row([new StringCell($breakdown['title'], self::text(10, self::MUTED))]));
        $writer->addRow(new Row([new StringCell(self::ADDRESS, self::text(8, self::MUTED))]));
        $writer->addRow(new Row([]));

        // --- the four cards -------------------------------------------------------------------
        $labels = [];
        $values = [];

        foreach ($breakdown['kpis'] as $i => $kpi) {
            $first = 0 === $i;
            $last = count($breakdown['kpis']) - 1 === $i;

            $labels[] = new StringCell($kpi['label'], self::card(7, self::MUTED, bold: true, left: $first));
            $values[] = Cell::fromValue(
                'charge' === $kpi['type'] ? -abs((float) $kpi['value']) : $kpi['value'],
                self::card(12, self::INK, bold: true, left: $first, format: match ($kpi['type']) {
                    'money' => self::MONEY,
                    'charge' => self::CHARGE,
                    default => null,
                }),
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

        // --- the table ------------------------------------------------------------------------
        $headerLeft = self::text(8, 'FFFFFF', bold: true)->withBackgroundColor(self::INK);
        $headerRight = $headerLeft->withCellAlignment(CellAlignment::RIGHT);
        $header = [];

        foreach ($columns as $column) {
            $header[] = new StringCell($column['label'], 'text' === $column['type'] ? $headerLeft : $headerRight);
        }

        $writer->addRow(new Row($header, 18.0));

        $bodyStyles = self::bodyStyles();

        foreach ($breakdown['rows'] as $row) {
            $writer->addRow(new Row(self::bodyCells($columns, $row, $bodyStyles)));
        }

        // A blank line, then the total — the gap is what stops a filter or a stray sort from
        // dragging the total row up into the data it is summarising.
        $writer->addRow(new Row([]));
        $writer->addRow(new Row(self::bodyCells($columns, $breakdown['totals'], self::totalStyles())));

        $lastDataRow = self::HEADER_ROW + count($breakdown['rows']);

        $sheet->setSheetView(new SheetView(freezeRow: self::HEADER_ROW + 1));

        // Only over the data itself: including the total row would let a filter hide it.
        if ($lastDataRow > self::HEADER_ROW) {
            $sheet->setAutoFilter(new AutoFilter(0, self::HEADER_ROW, $lastColumn, $lastDataRow));
        }
    }

    /**
     * Sheet 2 — the register, unstyled, exactly the columns the CSV has always carried.
     *
     * @param  array<string, mixed>  $source
     */
    private static function writeSource(Writer $writer, array $source): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Source Data');
        $sheet->setColumnWidth(26.0, 1);
        $sheet->setColumnWidth(18.0, 2);
        $sheet->setColumnWidthForRange(14.0, 3, max(3, count($source['columns'])));

        $headerStyle = self::text(9, self::INK, bold: true);
        $header = [];

        foreach ($source['columns'] as $label) {
            $header[] = new StringCell($label, $headerStyle);
        }

        $writer->addRow(new Row($header));

        $text = self::text(9, self::INK);
        $money = self::text(9, self::INK)->withFormat('#,##0.00')->withCellAlignment(CellAlignment::RIGHT);

        foreach ($source['rows'] as $row) {
            $cells = [];

            foreach ($row as $i => $value) {
                // Eloquent hands decimals back as STRINGS. Left as they arrive they would land as
                // text cells: right-aligned by the format, invisible to SUM(), and wrong in the
                // only way a register must never be. `text` names the columns that really are prose.
                $cells[] = ! in_array($i, $source['text'], true) && is_numeric($value)
                    ? Cell::fromValue((float) $value, $money)
                    : new StringCell((string) $value, $text);
            }

            $writer->addRow(new Row($cells));
        }

        $sheet->setSheetView(new SheetView(freezeRow: 2));
    }

    /**
     * One table row, styled per its column's type.
     *
     * @param  list<array{label: string, type: string, width: float}>  $columns
     * @param  list<float|int|string|null>  $values
     * @param  array<string, Style>  $styles
     * @return list<Cell>
     */
    private static function bodyCells(array $columns, array $values, array $styles): array
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
     * The body row's four looks. A hairline under every row is what keeps 26 columns readable
     * across — without it the eye loses the line somewhere around the contributions.
     *
     * @return array<string, Style>
     */
    private static function bodyStyles(): array
    {
        $rule = new Border(new BorderPart(BorderName::BOTTOM, self::RULE, BorderWidth::THIN, BorderStyle::SOLID));

        $text = self::text(8, self::INK)->withBorder($rule);
        $money = $text->withCellAlignment(CellAlignment::RIGHT)->withFormat(self::MONEY);

        return [
            'text' => $text,
            'count' => $text->withCellAlignment(CellAlignment::RIGHT)->withFormat('#,##0'),
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
    private static function totalStyles(): array
    {
        $rule = new Border(new BorderPart(BorderName::TOP, self::INK, BorderWidth::MEDIUM, BorderStyle::SOLID));

        $text = self::text(8, self::INK, bold: true)->withBorder($rule);
        $money = $text->withCellAlignment(CellAlignment::RIGHT)->withFormat(self::MONEY);

        return [
            'text' => $text,
            'count' => $text->withCellAlignment(CellAlignment::RIGHT)->withFormat('#,##0'),
            'money' => $money,
            'charge' => $money->withFormat(self::CHARGE),
            'status' => self::text(7, self::MUTED, bold: true)->withBorder($rule),
        ];
    }

    private static function text(int $size, string $color, bool $bold = false): Style
    {
        return (new Style())
            ->withFontName(self::FACE)
            ->withFontSize($size)
            ->withFontColor($color)
            ->withFontBold($bold);
    }

    /** A cell of one of the four summary cards: filled, ruled top and bottom, open at the sides. */
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
