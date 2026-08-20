<?php

namespace Tests\Feature\Hr;

use App\Modules\Hr\Models\TaxVersion;
use App\Modules\Hr\Services\TaxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;

/** The withholding table is dated data instead of a `match` in PHP. */
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
     * See TaxService's docblock for why this differs from 's block-the-run stance.
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

    // --- the viewer -------------------------------------------------------------------

    /**
     * The bands moved into `hr_tax_versions` / `hr_tax_brackets` so a human could check them against
     * the BIR schedule — and then nothing exposed them, so the only way to see what payroll withholds
     * against was to query the database. Data nobody can read is not auditable.
     */
    public function test_hr_can_read_the_tax_table(): void
    {
        $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/tax-table?as_of=2026-08-07')
            ->assertOk()
            ->assertJsonPath('data.as_of', '2026-08-07')
            ->assertJsonPath('data.bands.0.min_annual', 0)
            ->assertJsonPath('data.bands.0.rate_on_excess', 0);
    }

    /**
     * The table stores only floors; the viewer derives each band's ceiling as the next floor − 0.01,
     * because the BIR prints ranges and a range is what makes it checkable.
     */
    public function test_the_viewer_derives_band_ceilings_and_leaves_the_top_open(): void
    {
        $bands = $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/tax-table?as_of=2026-08-07')
            ->json('data.bands');

        // The next floor MINUS one centavo: ₱250,000 itself belongs to the 15% band, so the exempt
        // range has to stop just short of it rather than overlap.
        $this->assertSame(249999.99, (float) $bands[0]['max_annual']);
        $this->assertSame(250000.0, (float) $bands[1]['min_annual']);
        $this->assertSame(
            round((float) $bands[2]['min_annual'] - 0.01, 2),
            (float) $bands[1]['max_annual'],
        );
        $this->assertNull($bands[count($bands) - 1]['max_annual'], 'the top band is open-ended');
    }

    /** `as_of` selects the version in force then — the point is checking what a past period used. */
    public function test_the_viewer_answers_for_the_version_in_force_on_the_date(): void
    {
        TaxVersion::create(['effective_from' => '2030-01-01', 'note' => 'A future schedule']);

        $today = $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/tax-table?as_of=2026-08-07')
            ->json('data.effective_from');

        $later = $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/tax-table?as_of=2030-06-01')
            ->json('data.effective_from');

        $this->assertNotSame($today, $later);
        $this->assertSame('2030-01-01', $later);
    }

    /** It lists every loaded version, so the viewer can see there is more than one table. */
    public function test_the_viewer_lists_every_version(): void
    {
        TaxVersion::create(['effective_from' => '2030-01-01', 'note' => 'A future schedule']);

        $versions = $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/tax-table?as_of=2026-08-07')
            ->json('data.versions');

        $this->assertGreaterThanOrEqual(2, count($versions));
    }

    /** Read-only — tax rates come from statute, and a wrong figure mis-withholds for everyone at once. */
    public function test_the_tax_table_cannot_be_written_through_the_api(): void
    {
        $this->actingAs($this->hrUser())
            ->postJson('/api/v1/hr/tax-table', ['bands' => []])
            ->assertStatus(405);
    }

    public function test_an_employee_cannot_read_the_tax_table(): void
    {
        // A plain authenticated user with no HR role — the tax table is HR-gated.
        $this->actingAs(\App\Models\User::factory()->create())
            ->getJson('/api/v1/hr/tax-table?as_of=2026-08-07')
            ->assertForbidden();
    }
}
