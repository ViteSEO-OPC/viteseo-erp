<?php

namespace Tests\Unit\Hr;

use App\Modules\Hr\Support\PayslipDocument;
use PHPUnit\Framework\TestCase;

/**
 * THE COMPENSATION TABLE'S ROWS.
 *
 * An ordinary period shows a fixed list whether or not it paid each one, because a missing
 * "Overtime" row is indistinguishable from overtime that was silently dropped. Those rows are
 * built HERE rather than by each renderer, so they carry their keys — which is what makes them
 * correctable, and what stops a renamed line being matched by name and printed twice.
 */
class PayslipEarningRowsTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $slip
     * @return array<string, array<string, mixed>>
     */
    private function byKey(array $slip, string $runType = 'regular', array $overrides = []): array
    {
        $out = [];

        foreach (PayslipDocument::earningLines($slip, $runType, [], $overrides) as $line) {
            $out[$line['key']] = $line;
        }

        return $out;
    }

    /** @return list<string> */
    private function keys(array $slip, string $runType = 'regular', array $overrides = []): array
    {
        return array_column(PayslipDocument::earningLines($slip, $runType, [], $overrides), 'key');
    }

    /** The whole point: a row that paid nothing is still a keyed line HR can click and correct. */
    public function test_the_always_shown_earnings_are_real_keyed_rows_even_at_zero(): void
    {
        $lines = $this->byKey(['period_base' => 20000.0]);

        foreach (['basic', 'overtime', 'night_diff', 'rest_day', 'holiday'] as $key) {
            $this->assertArrayHasKey($key, $lines, "{$key} should be a row even at zero");
            $this->assertSame($key, $lines[$key]['key'], "{$key} needs its key to be correctable");
        }

        $this->assertSame(0.0, $lines['overtime']['amount']);
    }

    /** The order the document has always printed, now stated once instead of in each renderer. */
    public function test_the_always_shown_earnings_come_back_in_the_documents_order(): void
    {
        $keys = $this->keys([
            'period_base' => 20000.0, 'overtime_pay' => 1500.0, 'night_diff_pay' => 300.0,
            'rest_day_premium' => 1100.0, 'holiday_premium' => 1200.0,
        ]);

        $this->assertSame(['basic', 'overtime', 'night_diff', 'rest_day', 'holiday'], $keys);
    }

    /** Only an ordinary period has a skeleton — the other run types never worked those hours. */
    public function test_other_run_types_get_no_skeleton(): void
    {
        $this->assertSame(['thirteenth_month'], $this->keys(['thirteenth_month' => 18000.0], 'thirteenth_month'));
    }

    /** A figure corrected to zero must not take its own line with it. */
    public function test_a_line_corrected_down_to_zero_stays_on_the_document(): void
    {
        $lines = $this->byKey(
            ['period_base' => 20000.0, 'reimbursements' => 0.0],
            'regular',
            ['reimbursement' => ['amount' => 0.0, 'original' => 450.0, 'id' => 1]],
        );

        // Without this the correction is invisible: no struck-through original, and no way back
        // to what payroll computed.
        $this->assertArrayHasKey('reimbursement', $lines);
        $this->assertSame(450.0, $lines['reimbursement']['original_amount']);
        $this->assertTrue($lines['reimbursement']['overridden']);
    }

    /** A line HR has RENAMED is still one line. Matching the skeleton by name used to double it. */
    public function test_a_renamed_line_is_not_printed_twice(): void
    {
        $lines = PayslipDocument::earningLines(
            ['period_base' => 20000.0, 'overtime_pay' => 1500.0],
            'regular',
            [],
            ['overtime' => ['amount' => null, 'label' => 'OT Premium', 'original' => null, 'id' => 2]],
        );

        $overtime = array_values(array_filter($lines, fn (array $l) => $l['key'] === 'overtime'));

        $this->assertCount(1, $overtime);
        $this->assertSame('OT Premium', $overtime[0]['name']);
        $this->assertSame('Overtime', $overtime[0]['original_name']);
    }

    /** The label HR typed on an added line IS the line's name, not a note beneath it. */
    public function test_an_added_line_is_named_by_the_label_hr_typed(): void
    {
        $lines = PayslipDocument::earningLines(['period_base' => 20000.0], 'regular', [[
            'id' => 7, 'kind' => 'pay', 'target_key' => null, 'amount' => 3000.0,
            'note' => 'Back pay for the 1 Aug raise',
            'reason' => 'Back-dated raise approved in August',
            'issued_on' => 'Aug 20, 2026',
        ]]);

        $added = array_values(array_filter($lines, fn (array $l) => $l['key'] === 'amendment:7'));

        $this->assertCount(1, $added);
        $this->assertSame('Back pay for the 1 Aug raise', $added[0]['name']);
        // The REASON underneath — the half that explains the line rather than naming it.
        $this->assertStringContainsString('Back-dated raise approved in August', $added[0]['note']);
        $this->assertStringContainsString('issued Aug 20, 2026', $added[0]['note']);
    }

    /** With no label typed, the catalogue's own wording — and a reversal is a line of its own. */
    public function test_an_added_line_with_no_label_falls_back_to_the_documents_wording(): void
    {
        $lines = PayslipDocument::earningLines(['period_base' => 20000.0], 'regular', [
            ['id' => 8, 'kind' => 'pay', 'target_key' => null, 'amount' => 500.0, 'note' => '   ',
                'reason' => 'Missed allowance', 'issued_on' => 'Aug 20, 2026'],
            ['id' => 9, 'kind' => 'pay_reversal', 'target_key' => null, 'amount' => -500.0, 'note' => null,
                'reason' => 'Paid twice', 'issued_on' => 'Aug 20, 2026'],
        ]);

        $byKey = array_column($lines, 'name', 'key');

        $this->assertSame('Adjustment', $byKey['amendment:8']);
        $this->assertSame('Pay Reversed', $byKey['amendment:9']);
    }

    /** A zero line that is NOT part of the skeleton and NOT corrected stays off the document. */
    public function test_an_ordinary_zero_line_is_still_dropped(): void
    {
        $this->assertNotContains('reimbursement', $this->keys(['period_base' => 20000.0]));
        $this->assertNotContains('thirteenth_month', $this->keys(['period_base' => 20000.0]));
    }
}
