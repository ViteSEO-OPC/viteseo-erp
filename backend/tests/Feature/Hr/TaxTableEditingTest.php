<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\TaxVersion;
use App\Modules\Hr\Services\TaxService;
use App\Modules\Hr\Services\TaxVersionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

/**
 * PUBLISHING A WITHHOLDING TAX TABLE.
 *
 * The table used to arrive by migration. Now HR types it — which buys a rate change without a
 * deploy, and costs the risk that one mistyped figure mis-withholds for a whole band at once.
 * These tests are the second half of that bargain: the table must add up before it is accepted,
 * and it must never reach backwards into a period that has already been priced.
 */
class TaxTableEditingTest extends HrTestCase
{
    use RefreshDatabase;

    private const URI = '/api/v1/hr/tax-table';

    /** The day these tests publish on, so "today" is not the wall clock. */
    private const TODAY = '2026-08-24';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse(self::TODAY.' 09:00', config('app.timezone_local')));
    }

    /**
     * The TRAIN table, as published — the shape everything here varies from.
     *
     * @return list<array{min_annual: float|int, base_tax: float|int, rate_on_excess: float}>
     */
    private function trainBands(): array
    {
        return [
            ['min_annual' => 0, 'base_tax' => 0, 'rate_on_excess' => 0],
            ['min_annual' => 250000, 'base_tax' => 0, 'rate_on_excess' => 0.15],
            ['min_annual' => 400000, 'base_tax' => 22500, 'rate_on_excess' => 0.20],
            ['min_annual' => 800000, 'base_tax' => 102500, 'rate_on_excess' => 0.25],
            ['min_annual' => 2000000, 'base_tax' => 402500, 'rate_on_excess' => 0.30],
            ['min_annual' => 8000000, 'base_tax' => 2202500, 'rate_on_excess' => 0.35],
        ];
    }

    /**
     * The same table with the exempt tier raised to ₱350,000 — the change the GINHAWA bill
     * would make, and the reason this feature exists.
     *
     * @return list<array{min_annual: float|int, base_tax: float|int, rate_on_excess: float}>
     */
    private function raisedExemptionBands(): array
    {
        return [
            ['min_annual' => 0, 'base_tax' => 0, 'rate_on_excess' => 0],
            ['min_annual' => 350000, 'base_tax' => 0, 'rate_on_excess' => 0.15],
            ['min_annual' => 400000, 'base_tax' => 7500, 'rate_on_excess' => 0.20],
            ['min_annual' => 800000, 'base_tax' => 87500, 'rate_on_excess' => 0.25],
            ['min_annual' => 2000000, 'base_tax' => 387500, 'rate_on_excess' => 0.30],
            ['min_annual' => 8000000, 'base_tax' => 2187500, 'rate_on_excess' => 0.35],
        ];
    }

    /** @param list<array<string, mixed>> $bands */
    private function publish(array $bands, ?string $note = null)
    {
        return $this->actingAsHr()->putJson(self::URI, array_filter([
            'bands' => $bands,
            'note' => $note,
        ]));
    }

    private function employeeUser(): User
    {
        if (Role::query()->where('name', 'hr_employee')->doesntExist()) {
            $this->seed(RolesAndPermissionsSeeder::class);
        }

        $user = User::factory()->create();
        $user->assignRole('hr_employee');

        return $user;
    }

    public function test_the_seeded_table_is_the_bir_schedule_in_force(): void
    {
        // Guards the migration's figures, which every payslip's withholding depends on.
        $this->actingAsHr()->getJson(self::URI.'?as_of='.self::TODAY)
            ->assertOk()
            ->assertJsonPath('data.bands.1.min_annual', 250000)
            ->assertJsonPath('data.bands.2.base_tax', 22500)
            ->assertJsonPath('data.bands.5.min_annual', 8000000)
            ->assertJsonPath('data.bands.5.base_tax', 2202500);

        $tax = app(TaxService::class);
        $this->assertSame(0.0, $tax->annualTax(250000, self::TODAY));
        $this->assertSame(22500.0, $tax->annualTax(400000, self::TODAY));
        $this->assertSame(152500.0, $tax->annualTax(1000000, self::TODAY));
    }

    public function test_a_published_table_takes_effect_today_and_prices_payroll(): void
    {
        $this->publish($this->raisedExemptionBands(), 'Test schedule')->assertOk();

        $version = TaxVersion::whereDate('effective_from', self::TODAY)->first();
        $this->assertNotNull($version);
        $this->assertSame('Test schedule', $version->note);
        $this->assertCount(6, $version->brackets);

        // ₱500,000: 7,500 + 20% of 100,000 under the new table, against 42,500 under the old.
        $this->assertSame(27500.0, app(TaxService::class)->annualTax(500000, self::TODAY));
    }

    public function test_it_never_reaches_back_into_a_period_already_priced(): void
    {
        $this->publish($this->raisedExemptionBands())->assertOk();

        // The whole point of dating forward: yesterday still resolves the table that was in
        // force yesterday, so a run already priced cannot silently change.
        $this->assertSame(42500.0, app(TaxService::class)->annualTax(500000, '2026-08-23'));
    }

    public function test_publishing_twice_in_a_day_replaces_that_days_table(): void
    {
        $this->publish($this->raisedExemptionBands())->assertOk();
        $this->publish($this->trainBands(), 'Reverted')->assertOk();

        $this->assertSame(1, TaxVersion::whereDate('effective_from', self::TODAY)->count());
        $this->assertSame(6, TaxVersion::whereDate('effective_from', self::TODAY)->first()->brackets()->count());
        $this->assertSame(42500.0, app(TaxService::class)->annualTax(500000, self::TODAY));
    }

    public function test_a_table_whose_bands_do_not_add_up_is_refused(): void
    {
        // 25,500 where the bands below add up to 22,500 — the failure this feature's whole
        // safety case rests on. Nothing downstream would notice it.
        $bands = $this->trainBands();
        $bands[2]['base_tax'] = 25500;

        $response = $this->publish($bands)
            ->assertStatus(422)
            ->assertJsonValidationErrors('bands.2.base_tax');

        // And it says what the figure should have been, rather than only that it is wrong.
        $this->assertStringContainsString(
            '22,500.00',
            (string) ($response->json('errors')['bands.2.base_tax'][0] ?? ''),
        );

        $this->assertSame(0, TaxVersion::whereDate('effective_from', self::TODAY)->count());
    }

    public function test_a_rate_typed_as_a_percentage_is_refused(): void
    {
        // 15 instead of 0.15 would withhold fifteen times over. The bound catches it.
        $bands = $this->trainBands();
        $bands[1]['rate_on_excess'] = 15;

        $this->publish($bands)
            ->assertStatus(422)
            ->assertJsonValidationErrors('bands.1.rate_on_excess');
    }

    public function test_the_table_must_start_at_zero_and_ascend(): void
    {
        $notFromZero = $this->trainBands();
        $notFromZero[0]['min_annual'] = 50000;
        $this->publish($notFromZero)
            ->assertStatus(422)
            ->assertJsonValidationErrors('bands.0.min_annual');

        $outOfOrder = $this->trainBands();
        $outOfOrder[3]['min_annual'] = 300000;
        $this->publish($outOfOrder)
            ->assertStatus(422)
            ->assertJsonValidationErrors('bands.3.min_annual');

        $tooShort = [['min_annual' => 0, 'base_tax' => 0, 'rate_on_excess' => 0]];
        $this->publish($tooShort)
            ->assertStatus(422)
            ->assertJsonValidationErrors('bands');
    }

    public function test_a_table_published_in_error_can_be_removed(): void
    {
        $this->publish($this->raisedExemptionBands())->assertOk();
        $version = TaxVersion::whereDate('effective_from', self::TODAY)->firstOrFail();

        $this->actingAsHr()->deleteJson('/api/v1/hr/tax-versions/'.$version->id)->assertOk();

        // The seeded table applies again, brackets and all.
        $this->assertSame(0, TaxVersion::whereDate('effective_from', self::TODAY)->count());
        $this->assertSame(42500.0, app(TaxService::class)->annualTax(500000, self::TODAY));
    }

    public function test_the_last_table_cannot_be_removed(): void
    {
        $only = TaxVersion::orderBy('effective_from')->firstOrFail();

        $this->actingAsHr()->deleteJson('/api/v1/hr/tax-versions/'.$only->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors('version');

        // Because a table-less payroll withholds ₱0 from everybody and still looks like it ran.
        $this->assertSame(42500.0, app(TaxService::class)->annualTax(500000, self::TODAY));
    }

    public function test_only_hr_may_publish_a_tax_table(): void
    {
        Sanctum::actingAs($this->employeeUser());

        $this->putJson(self::URI, ['bands' => $this->raisedExemptionBands()])->assertForbidden();
        $this->assertSame(0, TaxVersion::whereDate('effective_from', self::TODAY)->count());
    }

    public function test_publishing_is_recorded_with_what_changed(): void
    {
        $this->publish($this->raisedExemptionBands(), 'GINHAWA-shaped')->assertOk();

        $entry = \App\Modules\Hr\Models\AuditLog::where('module', 'Payroll')
            ->where('description', 'like', '%withholding tax table%')
            ->latest('id')
            ->first();

        $this->assertNotNull($entry);
        // The exempt ceiling is the one figure a reader recognises, so it goes in the line.
        $this->assertStringContainsString('350,000.00', $entry->description);
        $this->assertEquals(250000, $entry->before['bands'][1]['from']);
        $this->assertEquals(350000, $entry->after['bands'][1]['from']);
    }

    public function test_the_service_reports_the_date_the_editor_publishes_for(): void
    {
        $this->actingAsHr()->getJson(self::URI)
            ->assertOk()
            ->assertJsonPath('data.today', self::TODAY)
            ->assertJsonPath('data.has_version_today', false);

        app(TaxVersionService::class)->save($this->trainBands(), 'republished');

        $this->actingAsHr()->getJson(self::URI)
            ->assertOk()
            ->assertJsonPath('data.has_version_today', true);
    }
}
