<?php

namespace Tests\Unit\Hr;

use App\Modules\Hr\Support\PayslipDocument;
use PHPUnit\Framework\TestCase;

/**
 * THE DEDUCTIONS TABLE'S ROWS, and which one a correction actually lands on.
 *
 * Every day-costing charge — absences, half-days, an unpaid suspension — writes to ONE column,
 * `absence_deduction`. The per-row figures are not stored: they are re-derived here by splitting
 * that column across the attendance ledger's days. A correction has to survive that split, or the
 * money HR entered against one row prints against another.
 */
class PayslipDeductionRowsTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $extra
     * @param  list<array{kind: string, days: float}>  $lines
     * @return array<string, mixed>
     */
    private function slip(float $absenceCharge, array $lines = [], array $extra = []): array
    {
        return [
            'absence_deduction' => $absenceCharge,
            'late_deduction' => 0.0,
            'undertime_deduction' => 0.0,
            'gross' => 20000.0,
            'other_deductions' => 0.0,
            'attendance_lines' => array_map(
                fn (array $l) => ['kind' => $l['kind'], 'days' => $l['days'], 'date' => '2026-08-03', 'detail' => null],
                $lines,
            ),
            ...$extra,
        ];
    }

    /** @return array<string, float> */
    private function amounts(array $slip, array $overrides = []): array
    {
        $out = [];

        foreach (PayslipDocument::deductionRows($slip, $overrides) as $row) {
            $out[$row['key']] = $row['amount'];
        }

        return $out;
    }

    /** A correction to a row the ledger never charged. The row it names is the row it lands on. */
    public function test_a_correction_pins_a_charge_row_that_has_no_attendance_lines(): void
    {
        // Two absence days cost ₱2,000. HR charges ₱1,000 of unpaid suspension the ledger missed,
        // which the service adds to the one shared column — so the pool is now ₱3,000.
        $amounts = $this->amounts(
            $this->slip(3000.0, [['kind' => 'absent', 'days' => 2.0]]),
            ['suspended_unpaid' => ['amount' => 1000.0, 'original' => 0.0, 'id' => 1]],
        );

        $this->assertSame(1000.0, $amounts['suspended_unpaid']);
        // The absences row is what it always was. Before this was fixed it read ₱3,000: it had
        // absorbed the whole suspension charge, because the suspension row carried no days to
        // pin the correction to.
        $this->assertSame(2000.0, $amounts['absent']);
    }

    /** The same on a payslip with no attendance at all, which takes the legacy fallback path. */
    public function test_a_correction_on_an_otherwise_clean_payslip_does_not_become_an_absence(): void
    {
        $amounts = $this->amounts(
            $this->slip(800.0),
            ['half_day' => ['amount' => 800.0, 'original' => 0.0, 'id' => 2]],
        );

        $this->assertSame(800.0, $amounts['half_day']);
        $this->assertSame(0.0, $amounts['absent']);
    }

    /** A charge the document does not always draw still gets a row once it is corrected. */
    public function test_a_correction_brings_a_row_the_table_does_not_always_show_into_existence(): void
    {
        $amounts = $this->amounts(
            $this->slip(1500.0),
            ['leave_unpaid' => ['amount' => 1500.0, 'original' => 0.0, 'id' => 3]],
        );

        $this->assertArrayHasKey('leave_unpaid', $amounts);
        $this->assertSame(1500.0, $amounts['leave_unpaid']);
    }

    /** The rows are a split of one column, so they have to foot to it whatever HR has pinned. */
    public function test_the_rows_still_foot_to_the_charge_they_are_a_split_of(): void
    {
        $slip = $this->slip(3000.0, [['kind' => 'absent', 'days' => 2.0]]);
        $overrides = ['suspended_unpaid' => ['amount' => 1000.0, 'original' => 0.0, 'id' => 4]];

        $this->assertSame(
            PayslipDocument::totals($slip)['absences'],
            round(array_sum($this->amounts($slip, $overrides)), 2),
        );
    }

    /** Correcting a row that DOES have days was already right, and stays right. */
    public function test_correcting_a_charge_the_ledger_did_record_leaves_the_others_alone(): void
    {
        $amounts = $this->amounts(
            $this->slip(2500.0, [
                ['kind' => 'absent', 'days' => 1.0],
                ['kind' => 'suspended_unpaid', 'days' => 1.0],
            ]),
            ['suspended_unpaid' => ['amount' => 1500.0, 'original' => 1000.0, 'id' => 5]],
        );

        $this->assertSame(1500.0, $amounts['suspended_unpaid']);
        $this->assertSame(1000.0, $amounts['absent']);
    }

    /** An uncorrected payslip from before the ledger existed still attributes its charge. */
    public function test_a_payslip_with_no_ledger_still_shows_its_charge_against_absences(): void
    {
        $amounts = $this->amounts($this->slip(1200.0));

        $this->assertSame(1200.0, $amounts['absent']);
    }

    /** Late and undertime are split from their own clamped pool and must not be disturbed. */
    public function test_a_day_charge_correction_does_not_disturb_the_time_charges(): void
    {
        $slip = $this->slip(
            2700.0,
            [['kind' => 'absent', 'days' => 2.0], ['kind' => 'late', 'days' => 0.0]],
            ['late_deduction' => 500.0],
        );

        $amounts = $this->amounts($slip, [
            'suspended_unpaid' => ['amount' => 200.0, 'original' => 0.0, 'id' => 6],
        ]);

        $this->assertSame(500.0, $amounts['late']);
        $this->assertSame(200.0, $amounts['suspended_unpaid']);
        $this->assertSame(2000.0, $amounts['absent']);
    }
}
