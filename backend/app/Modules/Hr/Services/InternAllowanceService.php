<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\InternAllowanceVersion;
use App\Modules\Hr\Models\PayrollSetting;
use App\Modules\Hr\Support\InternAllowancePolicy;
use App\Modules\Hr\Support\InternDay;
use App\Modules\Hr\Support\InternPreviewScenario;
use App\Modules\Hr\Support\InternRateContext;
use App\Modules\Hr\Support\PayslipDocument;
use App\Support\CurrentOrg;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Company → Intern Allowance. One company-wide policy, remembered by effective date the same way
 * the attendance policy is: payroll prices a cycle at the version in force on the cycle's last
 * day, so a change saved today never reprices a cycle that already ended.
 */
class InternAllowanceService
{
    /** Far enough back that no pay cycle predates it, so `asOf()` always finds a version. */
    public const OPENING_DATE = '2000-01-01';

    /**
     * Per-request memo, keyed by ORG and date. A payroll run resolves one date for every intern in
     * it, so the memo is what keeps that one query. The org belongs in the key because these rows
     * are per tenant: a container that outlives a single request — a queue worker walking orgs —
     * would otherwise hand one company's rules to the next.
     *
     * @var array<string, InternAllowancePolicy>
     */
    private array $asOfMemo = [];

    /**
     * Whose opening version has been checked this request. Per org for the same reason: the opening
     * row is one per tenant, and a shared `true` would skip filing the next company's.
     *
     * @var array<int|string, bool>
     */
    private array $openingChecked = [];

    public function __construct(
        private readonly AuditService $audit,
        private readonly AttendancePolicyService $attendancePolicy,
        private readonly InternAllowancePricer $pricer,
    ) {}

    /** The rules in force on $date (Manila, Y-m-d) — the latest version dated on or before it. */
    public function asOf(?string $date = null): InternAllowancePolicy
    {
        $date ??= $this->today();
        $key = CurrentOrg::id() . '|' . $date;

        if (isset($this->asOfMemo[$key])) {
            return $this->asOfMemo[$key];
        }

        $this->ensureOpeningVersion();

        $version = InternAllowanceVersion::query()
            ->whereDate('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        return $this->asOfMemo[$key] = $version
            ? InternAllowancePolicy::fromPayload($version->payload)
            : InternAllowancePolicy::defaults();
    }

    /**
     * The effective date of the version in force on $date, for the payslip snapshot.
     */
    public function versionDateAsOf(string $date): ?string
    {
        $this->ensureOpeningVersion();

        return InternAllowanceVersion::query()
            ->whereDate('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first()
            ?->effective_from
            ->toDateString();
    }

    /**
     * The allowance this intern is paid in the policy's unit: their own when HR set one, the
     * company default otherwise.
     *
     * @return array{amount: float, source: string}
     */
    public static function amountFor(Employee $employee, InternAllowancePolicy $rules): array
    {
        if ($employee->allowance_amount !== null) {
            return ['amount' => (float) $employee->allowance_amount, 'source' => 'override'];
        }

        return ['amount' => self::defaultAmount($rules), 'source' => 'default'];
    }

    public static function defaultAmount(InternAllowancePolicy $rules): float
    {
        return (float) $rules->get(match ($rules->get('method')) {
            'per_hour' => 'hourly_amount',
            'fixed_monthly' => 'monthly_amount',
            default => 'daily_amount',
        });
    }

    /**
     * File the opening version — what the rules said before anyone changed them. Idempotent.
     *
     * @return bool whether a row was written
     */
    public function ensureOpeningVersion(): bool
    {
        $org = CurrentOrg::id();
        if ($this->openingChecked[$org] ?? false) {
            return false;
        }
        $this->openingChecked[$org] = true;

        $version = InternAllowanceVersion::firstOrCreate(
            ['effective_from' => self::OPENING_DATE],
            ['payload' => InternAllowancePolicy::defaults()->toArray(), 'created_by' => null],
        );

        return $version->wasRecentlyCreated;
    }

    /**
     * Everything the settings page shows: the rules in force today, and every version — past,
     * current and scheduled.
     *
     * @param  list<string>  $correctedFields  settings the last save corrected (see InternAllowancePolicy::normalise)
     * @return array<string, mixed>
     */
    public function overview(array $correctedFields = [], ?string $savedEffectiveFrom = null): array
    {
        $this->ensureOpeningVersion();
        $today = $this->today();

        $versions = InternAllowanceVersion::query()
            ->with('creator:id,name')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();

        $current = $versions->first(fn (InternAllowanceVersion $v) => $v->effective_from->toDateString() <= $today);

        return [
            'settings' => ($current
                ? InternAllowancePolicy::fromPayload($current->payload)
                : InternAllowancePolicy::defaults())->toArray(),
            'effective_from' => $current?->effective_from->toDateString(),
            'saved_effective_from' => $savedEffectiveFrom,
            'corrected_fields' => $correctedFields,
            'options' => InternAllowancePolicy::OPTIONS,
            'versions' => $versions->map(fn (InternAllowanceVersion $v) => [
                'effective_from' => $v->effective_from->toDateString(),
                'status' => $v->effective_from->toDateString() > $today
                    ? 'scheduled'
                    : ($current && $v->is($current) ? 'current' : 'past'),
                'settings' => InternAllowancePolicy::fromPayload($v->payload)->toArray(),
                'created_by' => $v->creator?->name,
                'updated_at' => $v->updated_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * Save a version. Full replacement: the page sends every setting.
     *
     * @param  array<string, mixed>  $data  validated settings + effective_from + confirm_override_reinterpretation
     * @return array{effective_from: string, corrected_fields: list<string>}
     */
    public function update(array $data): array
    {
        $effectiveFrom = $data['effective_from'] ?? null ?: $this->today();
        [$payload, $corrected] = InternAllowancePolicy::normalise($data);

        $before = $this->asOf($effectiveFrom)->toArray();

        if ($payload['method'] !== $before['method'] && ! ($data['confirm_override_reinterpretation'] ?? false)) {
            $this->assertNoOverridesToReinterpret($before['method'], $payload['method']);
        }

        $version = InternAllowanceVersion::updateOrCreate(
            ['effective_from' => $effectiveFrom],
            ['payload' => $payload, 'created_by' => auth()->id()],
        );
        $this->asOfMemo = [];

        $changedBefore = [];
        $changedAfter = [];
        foreach ($payload as $key => $value) {
            if (($before[$key] ?? null) !== $value) {
                $changedBefore[$key] = $before[$key] ?? null;
                $changedAfter[$key] = $value;
            }
        }

        $this->audit->log(
            'updated',
            'Rules',
            ($changedAfter === []
                ? 'Intern allowance rules saved (no changes)'
                : 'Intern allowance rules updated: ' . implode(', ', array_keys($changedAfter)))
                . " — effective {$effectiveFrom}",
            $version,
            $changedBefore ?: null,
            $changedAfter ?: null,
        );

        return ['effective_from' => $effectiveFrom, 'corrected_fields' => $corrected];
    }

    /**
     * Withdraw a version that has not taken effect yet. A version already in force is history a
     * payroll run may have priced against, so it stays; change it by saving a new one.
     */
    public function deleteFutureVersion(string $date): void
    {
        if ($date <= $this->today()) {
            throw ValidationException::withMessages([
                'effective_from' => ['Only a version that has not taken effect yet can be withdrawn. Save a new version to change the rules from today.'],
            ]);
        }

        $version = InternAllowanceVersion::query()->whereDate('effective_from', $date)->firstOrFail();
        $version->delete();
        $this->asOfMemo = [];

        $this->audit->log(
            'removed',
            'Rules',
            "Withdrew the intern allowance rules scheduled for {$date}",
            $version,
            InternAllowancePolicy::fromPayload($version->payload)->toArray(),
            null,
        );
    }

    /**
     * Price an invented month under settings that are not saved — the Calculation Preview. Runs the
     * same pricer payroll runs, at the company's premium rates in force today. Writes nothing.
     *
     * @param  array<string, mixed>  $settings  validated settings, as they stand on the page
     * @param  array<string, mixed>  $sample  validated preview sample
     * @return array<string, mixed>
     */
    public function preview(array $settings, array $sample): array
    {
        [$payload, $corrected] = InternAllowancePolicy::normalise($settings);
        $rules = InternAllowancePolicy::fromPayload($payload);

        $override = $sample['amount_override'] ?? null;
        $frequency = $sample['frequency'] ?? PayrollSetting::current()->frequency;
        $days = InternPreviewScenario::days($sample);

        $pricing = $this->pricer->price(
            $days,
            $rules,
            new InternRateContext(
                amount: $override !== null ? (float) $override : self::defaultAmount($rules),
                amountSource: $override !== null ? 'override' : 'default',
                periodsPerYear: PayScheduleService::periodsPerYear($frequency),
                scheduledWorkdays: count(array_filter($days, fn (InternDay $d) => $d->isWorkday)),
            ),
            $this->attendancePolicy->asOf($this->today()),
        );

        $slip = $pricing->toSlipFields();
        $slip['gross'] = $pricing->earnings();

        return [
            'settings' => $payload,
            'corrected_fields' => $corrected,
            'rates' => [
                'method' => $pricing->method,
                'amount' => $pricing->amount,
                'amount_source' => $pricing->amountSource,
                'daily_equivalent' => $pricing->dailyRate,
                'hourly_equivalent' => $pricing->hourlyRate,
                'frequency' => $frequency,
            ],
            'earnings' => PayslipDocument::earningLines($slip),
            // The preview shows what moved; the payslip itself keeps its always-drawn empty rows.
            'deductions' => array_values(array_filter(
                PayslipDocument::deductionRows($slip),
                fn (array $row) => $row['count'] > 0 || round((float) $row['amount'], 2) != 0.0,
            )),
            'totals' => [
                'allowance' => $slip['period_base'],
                'premiums' => round($pricing->holidayPay + $pricing->restDayPay + $pricing->overtimePay + $pricing->nightDiffPay, 2),
                'deductions' => $slip['absence_deduction'],
                'net_allowance' => $slip['gross'],
            ],
            'lines' => $pricing->charges,
            'flags' => $pricing->flags,
        ];
    }

    /**
     * A per-intern allowance is read in the unit the company calculates in. Switching the unit
     * silently re-reads every one of them — ₱650 a day becomes ₱650 an hour — so it is refused
     * until HR says they have seen who that affects.
     */
    private function assertNoOverridesToReinterpret(string $from, string $to): void
    {
        $interns = Employee::query()
            ->where('employment_status', 'ojt')
            ->whereNotNull('allowance_amount')
            ->with('user:id,name')
            ->get();

        if ($interns->isEmpty()) {
            return;
        }

        $names = $interns->take(5)->map(fn (Employee $e) => $e->user?->name ?? "#{$e->id}")->implode(', ')
            . ($interns->count() > 5 ? ' and ' . ($interns->count() - 5) . ' more' : '');

        throw ValidationException::withMessages([
            'method' => [
                "{$interns->count()} intern" . ($interns->count() === 1 ? ' has' : 's have')
                    . " their own allowance ({$names}), recorded " . self::unitLabel($from)
                    . '. Calculating ' . self::unitLabel($to) . ' will read those same amounts '
                    . self::unitLabel($to) . '. Confirm to save anyway, then review their allowances.',
            ],
        ]);
    }

    private static function unitLabel(string $method): string
    {
        return match ($method) {
            'per_day' => 'per day',
            'per_hour' => 'per hour',
            'fixed_monthly' => 'per month',
            default => $method,
        };
    }

    private function today(): string
    {
        return Carbon::now(config('app.timezone_local'))->toDateString();
    }
}
