<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Http\Resources\PayslipResource;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Holiday;
use App\Modules\Hr\Models\HolidaySet;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Support\PayslipDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * THE HOLIDAY FIGURE HAS TO EXPLAIN ITSELF. It used to print as one lump sum with no note at
 * all: an employee who worked two holidays saw a single number and had no way to tell which
 * days earned it, at what rate, or why one of them paid twice what the other did.
 */
class HolidayPremiumBreakdownTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    /** Wednesday and Thursday of the first cutoff — both scheduled workdays. */
    private const REGULAR_DAY = '2026-08-05';

    private const SPECIAL_DAY = '2026-08-06';

    /** Saturday — off the schedule, so a holiday landing here is on a REST day. */
    private const REST_DAY = '2026-08-08';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContributionSettingSeeder::class);

        $this->travelTo(Carbon::parse('2026-08-20 12:00', config('app.timezone_local')));

        WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 0, 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get();

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'employment_status' => 'regular',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);
    }

    private function holiday(string $date, string $type, string $name): void
    {
        $set = HolidaySet::firstOrCreate(
            ['country_code' => 'PH', 'year' => 2026],
            ['source' => 'manual'],
        );

        Holiday::create([
            'holiday_set_id' => $set->id,
            'name' => $name,
            'date' => $date,
            'type' => $type,
            'source' => 'manual',
        ]);
    }

    private function worked(string $date): void
    {
        $tz = config('app.timezone_local');

        Attendance::create([
            'employee_id' => $this->employee->id,
            'work_date' => $date,
            'time_in' => Carbon::parse("{$date} 08:00", $tz)->utc(),
            'time_out' => Carbon::parse("{$date} 17:00", $tz)->utc(),
            'status' => 'present', 'lunch_minutes' => 60, 'break_minutes' => 0,
            'source' => Attendance::SOURCE_MANUAL,
        ]);
    }

    private function payslip(): Payslip
    {
        $run = app(PayrollService::class)->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());

        return $run->payslips()->where('employee_id', $this->employee->id)->firstOrFail();
    }

    /** The Holiday line on the document, as both the screen and the PDF receive it. */
    private function holidayLine(Payslip $payslip): array
    {
        $lines = PayslipResource::make($payslip->load('payrollRun'))
            ->toArray(request())['earnings_breakdown'];

        $found = collect($lines)->firstWhere('key', 'holiday');
        $this->assertNotNull($found, 'the payslip has no Holiday line at all');

        return $found;
    }

    /** THE HEADLINE: two holidays worked → two dated lines under one total. */
    public function test_the_payslip_names_every_holiday_behind_the_figure(): void
    {
        $this->holiday(self::REGULAR_DAY, 'regular', 'Test Regular Holiday');
        $this->holiday(self::SPECIAL_DAY, 'special_non_working', 'Test Special Day');
        $this->worked(self::REGULAR_DAY);
        $this->worked(self::SPECIAL_DAY);

        $payslip = $this->payslip();
        $stored = $payslip->holiday_lines;

        $this->assertCount(2, $stored);
        $this->assertSame(self::REGULAR_DAY, $stored[0]['date']);
        $this->assertSame('Test Regular Holiday', $stored[0]['name']);
        $this->assertSame('regular', $stored[0]['type']);
        $this->assertSame(200, $stored[0]['rate_pct']);
        $this->assertFalse($stored[0]['rest_day']);
        $this->assertSame(self::SPECIAL_DAY, $stored[1]['date']);
        $this->assertSame(130, $stored[1]['rate_pct']);

        // The lines account for the whole figure, to the peso. A breakdown that does not add up
        // to the total it sits under is worse than no breakdown at all.
        $this->assertEqualsWithDelta(
            (float) $payslip->holiday_premium,
            array_sum(array_column($stored, 'amount')),
            0.01,
        );

        // The regular holiday pays the +100% add-on; the special day pays +30%.
        $this->assertEqualsWithDelta((float) $payslip->daily_rate, $stored[0]['amount'], 0.01);
        $this->assertEqualsWithDelta((float) $payslip->daily_rate * 0.3, $stored[1]['amount'], 0.01);
    }

    /** And the document turns them into words the employee can read. */
    public function test_the_document_prints_the_date_the_name_and_the_rate(): void
    {
        $this->holiday(self::REGULAR_DAY, 'regular', 'Test Regular Holiday');
        $this->worked(self::REGULAR_DAY);

        $line = $this->holidayLine($this->payslip());

        $this->assertSame('1 day', $line['note']);
        $this->assertCount(1, $line['dates']);
        $this->assertSame(self::REGULAR_DAY, $line['dates'][0]['date']);
        $this->assertSame('Test Regular Holiday · Regular holiday 200%', $line['dates'][0]['detail']);
        $this->assertGreaterThan(0, $line['dates'][0]['amount']);
    }

    /**
     * A holiday on a REST day pays the WHOLE rate rather than the add-on, because the salary
     * never covered that day. Twice the figure of the line above it, so the row has to say why.
     */
    public function test_a_rest_day_holiday_says_why_it_paid_the_full_rate(): void
    {
        $this->holiday(self::REST_DAY, 'regular', 'Saturday Holiday');
        $this->worked(self::REST_DAY);

        $payslip = $this->payslip();
        $stored = $payslip->holiday_lines;

        $this->assertCount(1, $stored);
        $this->assertTrue($stored[0]['rest_day']);
        // 200% of the daily rate, not the +100% add-on a scheduled workday would earn.
        $this->assertEqualsWithDelta((float) $payslip->daily_rate * 2, $stored[0]['amount'], 0.01);

        $this->assertStringContainsString(
            'on a rest day (full rate)',
            $this->holidayLine($payslip)['dates'][0]['detail'],
        );
    }

    /** An unworked holiday earns no premium, so it gets no line. */
    public function test_a_holiday_nobody_worked_produces_no_lines(): void
    {
        $this->holiday(self::REGULAR_DAY, 'regular', 'Test Regular Holiday');

        $this->assertNull($this->payslip()->holiday_lines);
    }

    /**
     * A payslip issued before the derivation was recorded keeps printing exactly as it always
     * has — a total with nothing under it. Deriving its days now would read TODAY'S holiday
     * calendar, which is the one answer a document of record must not give.
     */
    public function test_a_payslip_without_stored_lines_still_prints_its_total(): void
    {
        $lines = PayslipDocument::earningLines(['holiday_premium' => 1234.56, 'holiday_lines' => null]);
        $holiday = collect($lines)->firstWhere('key', 'holiday');

        $this->assertSame(1234.56, $holiday['amount']);
        $this->assertNull($holiday['dates']);
        $this->assertNull($holiday['note']);
    }
}
