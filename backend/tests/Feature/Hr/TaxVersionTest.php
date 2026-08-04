<?php

namespace Tests\Feature\Hr;

use App\Modules\Hr\Models\TaxVersion;
use App\Modules\Hr\Services\TaxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * FIN-027 — the withholding table is dated data instead of a `match` in PHP.
 *
 * THE FIRST TEST IS THE IMPORTANT ONE. Moving a tax table is only safe if it changes nothing, so
 * the replacement is checked against the exact arithmetic of the code it replaced:
 *
 *     $t <= 250000  => 0
 *     $t <= 400000  => 0.15 * ($t - 250000)
 *     $t <= 800000  => 22500 + 0.20 * ($t - 400000)
 *     $t <= 2000000 => 102500 + 0.25 * ($t - 800000)
 *     $t <= 8000000 => 402500 + 0.30 * ($t - 2000000)
 *     default       => 2202500 + 0.35 * ($t - 8000000)
 *
 * Every bracket boundary is included, plus a value either side of each, because an off-by-one on a
 * band edge is the failure this refactor could plausibly introduce and the one nobody would notice
 * until someone's payslip was wrong.
 */
class TaxVersionTest extends HrTestCase
{
    use RefreshDatabase;

    /** The table the hardcoded match implemented, reproduced independently of the code under test. */
    private function legacyTax(float $t): float
    {
        $tax = match (true) {
            $t <= 250000 => 0,
            $t <= 400000 => 0.15 * ($t - 250000),
            $t <= 800000 => 22500 + 0.20 * ($t - 400000),
            $t <= 2000000 => 102500 + 0.25 * ($t - 800000),
            $t <= 8000000 => 402500 + 0.30 * ($t - 2000000),
            default => 2202500 + 0.35 * ($t - 8000000),
        };

        return round(max(0, $tax), 2);
    }

    public static function incomes(): array
    {
        $values = [
            0, 1, 249999, 250000, 250001, 399999, 400000, 400001,
            799999, 800000, 800001, 1999999, 2000000, 2000001,
            7999999, 8000000, 8000001, 12000000,
            // ordinary salaries, so the common path is covered too
            180000, 360000, 540000, 720000, 960000,
        ];

        return array_map(fn ($v) => [(float) $v], $values);
    }

    #[DataProvider('incomes')]
    public function test_the_dated_table_matches_the_table_it_replaced(float $income): void
    {
        $this->assertSame(
            $this->legacyTax($income),
            app(TaxService::class)->annualTax($income, '2026-03-15'),
            "tax diverged from the previous hardcoded table at ₱{$income}",
        );
    }

    /** A period is priced at the table in force for it, not the newest one. */
    public function test_an_older_cycle_uses_the_older_table(): void
    {
        $new = TaxVersion::create(['effective_from' => '2027-01-01', 'note' => 'hypothetical']);
        $new->brackets()->createMany([
            ['min_annual' => 0, 'base_tax' => 0, 'rate_on_excess' => 0],
            ['min_annual' => 250000, 'base_tax' => 0, 'rate_on_excess' => 0.25], // deliberately different
        ]);

        $tax = app(TaxService::class);

        $this->assertSame(22500.0, $tax->annualTax(400000, '2026-12-31'), 'the 2026 cycle must use the old table');
        $this->assertSame(37500.0, $tax->annualTax(400000, '2027-06-30'), 'the 2027 cycle must use the new table');
    }

    /** The exempt tier is a real band, not an accident of falling off the bottom. */
    public function test_income_within_the_exempt_tier_is_untaxed(): void
    {
        $this->assertSame(0.0, app(TaxService::class)->annualTax(250000, '2026-03-15'));
    }

    /**
     * A cycle predating every recorded table uses the earliest one rather than withholding ₱0.
     * See TaxService's docblock for why this differs from FIN-035's block-the-run stance.
     */
    public function test_a_cycle_before_the_earliest_table_falls_back_rather_than_taxing_nothing(): void
    {
        TaxVersion::query()->update(['effective_from' => '2025-01-01']);

        $this->assertSame(
            22500.0,
            app(TaxService::class)->annualTax(400000, '2019-06-30'),
            'an old cycle silently withheld nothing',
        );
    }
}
