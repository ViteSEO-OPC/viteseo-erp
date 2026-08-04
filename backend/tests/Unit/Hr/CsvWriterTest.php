<?php

namespace Tests\Unit\Hr;

use App\Modules\Hr\Services\CsvWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * FIN-019 — spreadsheet formula injection in the payroll and report exports.
 *
 * A cell beginning `=`, `+`, `-` or `@` is executed as a formula by Excel, LibreOffice and Sheets.
 * Employee names, department names, audit descriptions and concern text are all user-controlled
 * and all reach a CSV that finance opens on their own machine.
 *
 * Unit-level on purpose: this is pure string handling, and a rule this cheap to test should not
 * need a payroll run to exercise it.
 */
class CsvWriterTest extends TestCase
{
    public static function dangerousCells(): array
    {
        return [
            'formula' => ['=cmd|\'/c calc\'!A1'],
            'plus' => ['+1+1'],
            'minus' => ['-2+3'],
            'at' => ['@SUM(A1:A9)'],
            'tab' => ["\t=1+1"],
            'carriage return' => ["\r=1+1"],
        ];
    }

    #[DataProvider('dangerousCells')]
    public function test_a_dangerous_leading_character_is_neutralised(string $value): void
    {
        $this->assertSame("'" . $value, CsvWriter::neutralise($value));
    }

    public function test_ordinary_text_is_left_alone(): void
    {
        foreach (['Maya Santos', 'Operations', 'Rice allowance', ''] as $safe) {
            $this->assertSame($safe, CsvWriter::neutralise($safe));
        }
    }

    /** Numbers must stay numbers, or every total in the export becomes text. */
    public function test_numeric_values_pass_through_untouched(): void
    {
        $this->assertSame(1234.56, CsvWriter::neutralise(1234.56));
        $this->assertSame(-99, CsvWriter::neutralise(-99));
        $this->assertNull(CsvWriter::neutralise(null));
    }

    /** The whole point: a crafted employee name cannot execute when the file is opened. */
    public function test_a_crafted_name_is_quoted_in_the_rendered_file(): void
    {
        $csv = CsvWriter::render([
            ['Employee', 'Department'],
            ['=cmd|\'/c calc\'!A1', 'Operations'],
        ], bom: false);

        $this->assertStringContainsString("'=cmd", $csv);
        $this->assertStringNotContainsString("\n=cmd", $csv);
        $this->assertStringNotContainsString('"=cmd', $csv);
    }

    public function test_the_bom_is_emitted_only_when_asked(): void
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", CsvWriter::render([['a']]));
        $this->assertStringStartsNotWith("\xEF\xBB\xBF", CsvWriter::render([['a']], bom: false));
    }
}
