<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\AuditLog;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\InternAllowanceVersion;
use App\Modules\Hr\Services\InternAllowanceService;
use App\Modules\Hr\Support\InternAllowancePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

/**
 * Company → Intern Allowance: one company-wide policy, remembered by the date each version took
 * effect, with every policy choice HR's to make.
 */
class InternAllowanceSettingsTest extends HrTestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/hr/intern-allowance-settings';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-15 09:00', config('app.timezone_local')));
    }

    /** A complete settings payload, the way the page sends it. */
    private function settings(array $overrides = []): array
    {
        return array_merge(InternAllowancePolicy::DEFAULTS, ['daily_amount' => 500], $overrides);
    }

    private function save(array $overrides = [], array $extra = []): TestResponse
    {
        return $this->actingAsHr()->putJson(self::URL, $this->settings($overrides) + $extra);
    }

    // --- versions -------------------------------------------------------------------------------

    public function test_a_company_that_never_saved_reads_the_defaults_from_an_opening_version(): void
    {
        $this->actingAsHr()->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.settings.method', 'per_day')
            ->assertJsonPath('data.settings.daily_amount', 0)
            ->assertJsonPath('data.effective_from', InternAllowanceService::OPENING_DATE);

        $this->assertSame(1, InternAllowanceVersion::count());
    }

    public function test_saving_files_a_version_dated_today_and_audits_what_changed(): void
    {
        $this->save(['daily_amount' => 650])
            ->assertOk()
            ->assertJsonPath('data.effective_from', '2026-09-15')
            ->assertJsonPath('data.settings.daily_amount', 650);

        $audit = AuditLog::query()->where('module', 'Rules')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertStringContainsString('daily_amount', $audit->description);
        $this->assertStringContainsString('effective 2026-09-15', $audit->description);
    }

    /** A period already run is corrected on its payslips, never by rewriting its rules. */
    public function test_a_past_effective_date_is_refused(): void
    {
        $this->save([], ['effective_from' => '2026-09-14'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('effective_from');
    }

    public function test_a_scheduled_version_does_not_apply_before_its_date(): void
    {
        $this->save(['daily_amount' => 600], ['effective_from' => '2026-10-01'])
            ->assertOk()
            ->assertJsonPath('data.saved_effective_from', '2026-10-01')
            ->assertJsonPath('data.versions.0.status', 'scheduled');

        $service = app(InternAllowanceService::class);
        $this->assertSame(0.0, $service->asOf('2026-09-30')->get('daily_amount'));
        $this->assertSame(600.0, $service->asOf('2026-10-01')->get('daily_amount'));
    }

    public function test_saving_twice_on_one_day_corrects_that_days_version(): void
    {
        $this->save(['daily_amount' => 600])->assertOk();
        $this->save(['daily_amount' => 700])->assertOk();

        $this->assertSame(1, InternAllowanceVersion::whereDate('effective_from', '2026-09-15')->count());
        $this->assertSame(700.0, app(InternAllowanceService::class)->asOf()->get('daily_amount'));
    }

    public function test_a_scheduled_version_can_be_withdrawn_but_one_in_force_cannot(): void
    {
        $this->save(['daily_amount' => 600], ['effective_from' => '2026-10-01'])->assertOk();

        $this->actingAsHr()->deleteJson(self::URL . '/versions/2026-10-01')->assertOk();
        $this->assertSame(0, InternAllowanceVersion::whereDate('effective_from', '2026-10-01')->count());

        $this->actingAsHr()->deleteJson(self::URL . '/versions/' . InternAllowanceService::OPENING_DATE)
            ->assertStatus(422)
            ->assertJsonValidationErrors('effective_from');
    }

    // --- validation -----------------------------------------------------------------------------

    public function test_the_amount_for_the_chosen_method_is_required(): void
    {
        $this->save(['method' => 'fixed_monthly', 'monthly_amount' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('monthly_amount');
    }

    public function test_a_custom_premium_needs_its_rate(): void
    {
        $premium = [
            'regular_holiday_worked' => 'allowance_with_premium',
            'regular_holiday_premium_source' => 'custom',
        ];

        $this->save($premium)->assertStatus(422)->assertJsonValidationErrors('regular_holiday_premium_pct');

        $this->save($premium + ['regular_holiday_premium_pct' => 150])
            ->assertOk()
            ->assertJsonPath('data.settings.regular_holiday_premium_pct', 150);
    }

    public function test_selected_leave_types_need_at_least_one_type(): void
    {
        $this->save(['leave_types_allowed' => 'selected', 'leave_type_ids' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('leave_type_ids');
    }

    public function test_an_option_outside_the_list_is_refused(): void
    {
        $this->save(['holiday_forfeit_after_absence' => false, 'overtime_rule' => 'double_pay'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('overtime_rule');
    }

    /**
     * The effective date is the version's KEY. Anything but Y-m-d misses the row it meant to
     * correct — and an offset-bearing string would be day-bounded in UTC rather than Manila.
     */
    public function test_an_effective_date_that_is_not_a_plain_date_is_refused(): void
    {
        $this->save([], ['effective_from' => '2026-10-01T00:00:00.000Z'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('effective_from');
    }

    /** A published ₱0 allowance would pay every intern nothing, and say nothing about it. */
    public function test_a_zero_allowance_cannot_be_published(): void
    {
        $this->save(['daily_amount' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('daily_amount');
    }

    /**
     * One instance per request: the dated-rules memo is per object, and resolving a fresh one per
     * leave row is how it came to issue a version query for every intern's request.
     */
    public function test_the_rules_service_is_resolved_once(): void
    {
        $this->assertSame(app(InternAllowanceService::class), app(InternAllowanceService::class));
    }

    // --- arithmetic the engine refuses to double count -------------------------------------------

    /**
     * Paid by the hour, a late intern has already not been paid for the minutes they missed. A
     * late deduction on top would charge those minutes twice.
     */
    public function test_hours_based_pay_cannot_also_dock_the_minutes_it_never_paid(): void
    {
        $this->save([
            'method' => 'per_hour',
            'hourly_amount' => 70,
            'late_rule' => 'fixed_amount',
            'late_fixed_amount' => 50,
            'half_day_rule' => 'pay_50',
        ])
            ->assertOk()
            ->assertJsonPath('data.settings.late_rule', 'none')
            ->assertJsonPath('data.settings.late_fixed_amount', null)
            ->assertJsonPath('data.settings.half_day_rule', 'actual_hours');

        $corrected = $this->save([
            'method' => 'per_hour',
            'hourly_amount' => 70,
            'late_rule' => 'fixed_amount',
            'late_fixed_amount' => 50,
        ])->json('data.corrected_fields');

        $this->assertContains('late_rule', $corrected);
        $this->assertContains('half_day_rule', $corrected);
    }

    /** Detail left behind by an option not chosen is not stored as if it meant something. */
    public function test_detail_of_an_unchosen_option_is_not_kept(): void
    {
        $this->save([
            'regular_holiday_worked' => 'allowance', // no premium
            'regular_holiday_premium_source' => 'custom',
            'regular_holiday_premium_pct' => 150,
        ])
            ->assertOk()
            ->assertJsonPath('data.settings.regular_holiday_premium_source', 'company_rate')
            ->assertJsonPath('data.settings.regular_holiday_premium_pct', null)
            ->assertJsonPath('data.settings.hourly_amount', 0);
    }

    // --- per-intern allowances --------------------------------------------------------------------

    /** ₱650 a day must not silently become ₱650 an hour. */
    public function test_changing_the_unit_while_interns_have_their_own_allowance_needs_confirmation(): void
    {
        $dept = Department::create(['name' => 'Engineering', 'code' => 'ENG']);
        Employee::create([
            'user_id' => User::factory()->create(['name' => 'Kim Reyes'])->id,
            'department_id' => $dept->id, 'employee_no' => 'E-OJT', 'job_title' => 'Intern',
            'employment_status' => 'ojt', 'hire_date' => '2026-06-01', 'base_salary' => 0,
            'allowance_amount' => 650,
        ]);

        $refused = $this->save(['method' => 'per_hour', 'hourly_amount' => 80]);
        $refused->assertStatus(422)->assertJsonValidationErrors('method');
        $this->assertStringContainsString('Kim Reyes', $refused->json('errors.method.0'));

        $this->save(['method' => 'per_hour', 'hourly_amount' => 80], ['confirm_override_reinterpretation' => true])
            ->assertOk()
            ->assertJsonPath('data.settings.method', 'per_hour');
    }

    public function test_only_hr_can_read_or_change_the_rules(): void
    {
        $this->actingAs(User::factory()->create())->getJson(self::URL)->assertForbidden();
        $this->actingAs(User::factory()->create())->putJson(self::URL, $this->settings())->assertForbidden();
    }
}
