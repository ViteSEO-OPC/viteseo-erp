<?php

namespace App\Modules\Hr\Services;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * THE PAYROLL BREAKDOWN AS A WORKBOOK.
 *
 * The design itself — letterhead, cards, ruled table, total — moved to ExportSheetWriter when
 * every other export was asked to look like this one. What stays here is payroll's own: the two
 * sheets it writes, and the unstyled register on the second.
 */
final class PayrollXlsxWriter
{
    /**
     * Write the workbook to `$path`.
     *
     * @param  array{title: string,
     *               kpis: list<array{label: string, value: float|int, type: string}>,
     *               columns: list<array{label: string, type: string, width: float}>,
     *               rows: list<list<float|int|string|null>>,
     *               totals: list<float|int|string|null>}  $breakdown
     * @param  array{columns: list<string>, rows: list<list<float|int|string|null>>, text: list<int>}  $source
     * @param  array{name: string, address: string, logo: ?string}  $letterhead  from CompanyProfileService
     */
    public static function write(string $path, array $breakdown, array $source, array $letterhead): void
    {
        $writer = new Writer(new Options());
        $writer->openToFile($path);

        ExportSheetWriter::write($writer, 'Payroll Export', $breakdown, $letterhead);
        self::writeSource($writer, $source);

        $writer->close();
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

        $headerStyle = ExportSheetWriter::text(9, ExportSheetWriter::INK, bold: true);
        $header = [];

        foreach ($source['columns'] as $label) {
            $header[] = new StringCell($label, $headerStyle);
        }

        $writer->addRow(new Row($header));

        $text = ExportSheetWriter::text(9, ExportSheetWriter::INK);
        $money = ExportSheetWriter::text(9, ExportSheetWriter::INK)->withFormat('#,##0.00')->withCellAlignment(CellAlignment::RIGHT);

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
}
