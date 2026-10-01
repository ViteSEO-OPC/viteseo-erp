<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\InternAllowanceVersion;
use App\Modules\Hr\Support\InternAllowancePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/** Company → Intern Allowance → Calculation Preview: the page's unsaved settings, priced. */
class InternAllowancePreviewTest extends HrTestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/hr/intern-allowance-settings/preview';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-15 09:00', config('app.timezone_local')));
    }

    private function payload(array $settings = [], array $sample = []): array
    {
        return array_merge(InternAllowancePolicy::DEFAULTS, ['daily_amount' => 500], $settings, [
            'sample' => array_merge(['scheduled_days' => 20, 'frequency' => 'semi_monthly'], $sample),
        ]);
    }

    public function test_the_preview_prices_the_sample_month_under_the_unsaved_settings(): void
    {
        $response = $this->actingAsHr()->postJson(self::URL, $this->payload([], [
            'absent_days' => 2,
            'half_days' => 1,
        ]));

        $response->assertOk()
            ->assertJsonPath('data.rates.daily_equivalent', 500)
            ->assertJsonPath('data.totals.allowance', 10000)
            ->assertJsonPath('data.totals.deductions', 1250)
            ->assertJsonPath('data.totals.net_allowance', 8750)
            ->assertJsonPath('data.earnings.0.name', 'Allowance');

        $rows = collect($response->json('data.deductions'))->keyBy('key');
        $this->assertEquals(1000, $rows['absent']['amount']);
        $this->assertEquals(250, $rows['half_day']['amount']);
    }

    public function test_an_intern_specific_amount_can_be_previewed(): void
    {
        $this->actingAsHr()->postJson(self::URL, $this->payload([], ['amount_override' => 650, 'scheduled_days' => 10]))
            ->assertOk()
            ->assertJsonPath('data.rates.amount_source', 'override')
            ->assertJsonPath('data.totals.net_allowance', 6500);
    }

    public function test_the_preview_reports_what_the_arithmetic_corrected(): void
    {
        $corrected = $this->actingAsHr()->postJson(self::URL, $this->payload([
            'method' => 'per_hour',
            'hourly_amount' => 60,
            'late_rule' => 'missed_hours',
        ]))->assertOk()->json('data.corrected_fields');

        $this->assertContains('late_rule', $corrected);
    }

    public function test_previewing_saves_nothing(): void
    {
        $this->actingAsHr()->getJson('/api/v1/hr/intern-allowance-settings')->assertOk();
        $before = InternAllowanceVersion::count();

        $this->actingAsHr()->postJson(self::URL, $this->payload(['daily_amount' => 900]))->assertOk();

        $this->assertSame($before, InternAllowanceVersion::count());
    }

    public function test_the_kinds_of_day_cannot_outnumber_the_scheduled_days(): void
    {
        $this->actingAsHr()->postJson(self::URL, $this->payload([], ['absent_days' => 15, 'leave_days' => 10]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('sample.scheduled_days');
    }

    public function test_only_hr_can_preview(): void
    {
        $this->actingAs(User::factory()->create())->postJson(self::URL, $this->payload())->assertForbidden();
    }
}
