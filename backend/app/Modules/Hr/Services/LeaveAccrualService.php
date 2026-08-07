<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveBalance;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\LeaveTypeAllotment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Leave that accrues monthly, carries into the next year and expires (FIN-034 `R` / MF-10).
 *
 * Balances used to be seeded once for the hire year and then hand-edited forever, so every January HR
 * re-entered the entire company or everyone's leave read zero. This is the engine that replaces that.
 *
 * EVERYTHING HERE RECOMPUTES; NOTHING INCREMENTS. `accrue()` works out what the balance SHOULD be for
 * the months elapsed and writes that figure, rather than adding a month's worth each time it runs. A
 * cron that fires twice on the 1st, a manual run after a missed month, an HR officer clicking the
 * button to check — none of them can hand out extra days. This is the same rule the loan ledger and
 * deduction arrears follow, and it is the reason `accrued_days` / `carried_days` are stored separately
 * from the `entitled_days` total everything else reads.
 *
 * IT NEVER REDUCES ENTITLEMENT BELOW WHAT IS ALREADY SPENT. Lowering `days_per_year`, tightening a
 * carry-over cap, or letting carried days expire can all compute a total under `used_days`. Leave that
 * has been taken cannot be un-taken — the employee was already off work — so the floor is `used_days`
 * and the shortfall is simply not clawed back. Allowing a negative remainder would mean a payslip
 * docking someone for a holiday HR approved months earlier.
 *
 * OPT-IN, per leave type. `accrual_mode = 'none'` is the shipped default and means "leave this type
 * exactly as it is": hand-managed, untouched by this service. Nothing in a migration starts moving
 * entitlements on its own.
 */
class LeaveAccrualService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * Credit accrual for one year, up to `$asOf`, for every type with accrual switched on.
     *
     * Idempotent: call it as often as you like. Returns the number of balance rows written, which is
     * what the command reports and the tests assert on.
     */
    public function accrue(int $year, ?string $asOf = null): int
    {
        $tz = config('app.timezone_local');
        $on = Carbon::parse($asOf ?? 'now', $tz);

        // Only the months of THIS year that have actually started. Accruing December in January
        // would credit a year that hasn't been worked; accruing nothing for a past year would make
        // a back-fill impossible.
        $throughMonth = match (true) {
            $on->year > $year => 12,
            $on->year < $year => 0,
            default => (int) $on->month,
        };

        if ($throughMonth === 0) {
            return 0; // the year hasn't begun
        }

        $types = LeaveType::whereIn('accrual_mode', ['monthly', 'annual'])->get();
        if ($types->isEmpty()) {
            return 0;
        }

        // Someone who left still needs their part-year accrual settled — final pay converts unused
        // leave to cash (FIN-014), and it can only convert what the balance says they have.
        $employees = Employee::withTrashed()->get();
        $allotments = $this->allotmentMap();
        $written = 0;

        foreach ($types as $type) {
            foreach ($employees as $employee) {
                if ($this->accrueOne($employee, $type, $year, $throughMonth, $allotments)) {
                    $written++;
                }
            }
        }

        return $written;
    }

    /**
     * Per-employment-status yearly entitlements, keyed "status|type_id".
     *
     * @return array<string, float>
     */
    private function allotmentMap(): array
    {
        return LeaveTypeAllotment::get(['employment_status', 'leave_type_id', 'days_per_year'])
            ->mapWithKeys(fn (LeaveTypeAllotment $a) => [
                $a->employment_status.'|'.$a->leave_type_id => (float) $a->days_per_year,
            ])
            ->all();
    }

    /**
     * The yearly entitlement this employee accrues toward.
     *
     * MIRRORS `EmployeeService`'s seeding precedence exactly — the per-employment-status allotment
     * first, the leave type's own figure as the fallback. Reading only the type would have quietly
     * given a probationary employee a regular employee's accrual rate, which is the sort of thing that
     * shows up as an over-entitlement months later with no obvious cause.
     *
     * @param  array<string, float>  $allotments
     */
    private function annualDaysFor(Employee $employee, LeaveType $type, array $allotments): float
    {
        return $allotments[$employee->employment_status.'|'.$type->id]
            ?? (float) $type->days_per_year;
    }

    /**
     * One employee, one type, one year. True when the row changed.
     *
     * Creates the balance row if it is missing, because "no row" and "zero days" are the same thing
     * to an accruing type and requiring HR to pre-create rows is the manual step being removed.
     *
     * @param  array<string, float>  $allotments
     */
    private function accrueOne(
        Employee $employee,
        LeaveType $type,
        int $year,
        int $throughMonth,
        array $allotments,
    ): bool {
        $months = $this->accruableMonths($employee, $type, $year, $throughMonth);
        $annual = $this->annualDaysFor($employee, $type, $allotments);

        $accrued = $type->accrual_mode === 'annual'
            // Annual credits the whole year's entitlement once the year has started — but still
            // nothing at all for someone who hadn't been hired yet.
            ? ($months > 0 ? $annual : 0.0)
            : round($annual / 12 * $months, 1);

        return DB::transaction(function () use ($employee, $type, $year, $accrued, $throughMonth) {
            $balance = LeaveBalance::where('employee_id', $employee->id)
                ->where('leave_type_id', $type->id)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if (! $balance) {
                if ($accrued <= 0) {
                    return false; // nothing to credit and nothing to create
                }

                LeaveBalance::create([
                    'employee_id' => $employee->id,
                    'leave_type_id' => $type->id,
                    'year' => $year,
                    'entitled_days' => $accrued,
                    'accrued_days' => $accrued,
                    'carried_days' => 0,
                    'used_days' => 0,
                    'accrued_through_month' => $throughMonth,
                ]);

                return true;
            }

            $carried = $this->liveCarriedDays($balance, $year);
            $entitled = $this->flooredTotal($accrued + $carried, (float) $balance->used_days);

            // Nothing moved — don't touch the row, so `updated_at` stays meaningful as "when this
            // entitlement last actually changed".
            if ($this->same($balance->entitled_days, $entitled)
                && $this->same($balance->accrued_days, $accrued)
                && (int) $balance->accrued_through_month === $throughMonth) {
                return false;
            }

            $balance->update([
                'entitled_days' => $entitled,
                'accrued_days' => $accrued,
                'accrued_through_month' => $throughMonth,
            ]);

            return true;
        });
    }

    /**
     * How many months of this year the employee accrues for.
     *
     * Pro-rating counts from the HIRE MONTH INCLUSIVE — somebody who started on 20 January accrues
     * for January. Counting only whole months worked would deny a month to almost every hire, and
     * the half-month arithmetic that would fix that is a policy nobody has specified.
     *
     * Separation is treated the same way at the other end: a leaver accrues through their final
     * month, so their final-pay leave conversion has the credits they earned in it.
     */
    private function accruableMonths(Employee $employee, LeaveType $type, int $year, int $throughMonth): int
    {
        $start = 1;
        $end = $throughMonth;

        $hire = $employee->hire_date ? Carbon::parse($employee->hire_date) : null;
        if ($hire) {
            if ($hire->year > $year) {
                return 0; // not yet employed
            }
            if ($hire->year === $year && $type->prorate_first_year) {
                $start = (int) $hire->month;
            }
        }

        $separation = $employee->separation_date ? Carbon::parse($employee->separation_date) : null;
        if ($separation) {
            if ($separation->year < $year) {
                return 0; // gone before this year began
            }
            if ($separation->year === $year) {
                $end = min($end, (int) $separation->month);
            }
        }

        return max(0, $end - $start + 1);
    }

    /**
     * Roll unused leave from `$year - 1` into `$year`, capped and dated for expiry.
     *
     * Idempotent by construction: it writes `carried_days` as an absolute figure, so running it twice
     * carries the same amount rather than stacking. Safe to run at any point in the year.
     */
    public function rolloverInto(int $year): int
    {
        $types = LeaveType::whereIn('accrual_mode', ['monthly', 'annual'])->get();
        if ($types->isEmpty()) {
            return 0;
        }

        $previous = LeaveBalance::where('year', $year - 1)
            ->whereIn('leave_type_id', $types->pluck('id'))
            ->get();

        $written = 0;

        foreach ($previous as $last) {
            $type = $types->firstWhere('id', $last->leave_type_id);
            if (! $type) {
                continue;
            }

            $unused = max(0.0, (float) $last->entitled_days - (float) $last->used_days);

            $cap = $type->carry_over_cap_days;
            $carried = $cap === null ? $unused : min($unused, (float) $cap);
            $carried = round($carried, 1);

            $expires = $type->carry_over_expiry_months === null
                ? null
                : Carbon::create($year, 1, 1)
                    ->addMonths((int) $type->carry_over_expiry_months)
                    ->toDateString();

            $written += DB::transaction(function () use ($last, $year, $carried, $expires) {
                $balance = LeaveBalance::where('employee_id', $last->employee_id)
                    ->where('leave_type_id', $last->leave_type_id)
                    ->where('year', $year)
                    ->lockForUpdate()
                    ->first();

                if (! $balance) {
                    if ($carried <= 0) {
                        return 0;
                    }

                    LeaveBalance::create([
                        'employee_id' => $last->employee_id,
                        'leave_type_id' => $last->leave_type_id,
                        'year' => $year,
                        'entitled_days' => $carried,
                        'accrued_days' => 0,
                        'carried_days' => $carried,
                        'carried_expires_on' => $expires,
                        'used_days' => 0,
                        'accrued_through_month' => 0,
                    ]);

                    return 1;
                }

                if ($this->same($balance->carried_days, $carried)
                    && (string) $balance->carried_expires_on?->toDateString() === (string) $expires) {
                    return 0;
                }

                // The total is rebuilt from both components, never adjusted by a delta.
                $balance->update([
                    'carried_days' => $carried,
                    'carried_expires_on' => $expires,
                    'entitled_days' => $this->flooredTotal(
                        (float) $balance->accrued_days + $carried,
                        (float) $balance->used_days,
                    ),
                ]);

                return 1;
            });
        }

        if ($written > 0) {
            $this->audit->log(
                'rolled_over',
                'Leave',
                "Carried unused leave into {$year} for {$written} balance(s)",
            );
        }

        return $written;
    }

    /**
     * Drop carried days whose expiry has passed, and rebuild the totals that depended on them.
     *
     * Expiry does NOT reclaim leave already taken — see the class docblock. If the employee spent the
     * carried days before they lapsed, the entitlement floors at `used_days` and nothing is clawed
     * back; what expires is the unspent remainder, which is the only part still available to lose.
     */
    public function expireCarriedDays(?string $asOf = null): int
    {
        $on = Carbon::parse($asOf ?? 'now', config('app.timezone_local'))->toDateString();

        $stale = LeaveBalance::whereNotNull('carried_expires_on')
            ->whereDate('carried_expires_on', '<=', $on)
            ->where('carried_days', '>', 0)
            ->get();

        foreach ($stale as $balance) {
            $balance->update([
                'carried_days' => 0,
                'entitled_days' => $this->flooredTotal(
                    (float) $balance->accrued_days,
                    (float) $balance->used_days,
                ),
            ]);
        }

        if ($stale->isNotEmpty()) {
            $this->audit->log(
                'expired',
                'Leave',
                "Expired carried leave on {$stale->count()} balance(s)",
            );
        }

        return $stale->count();
    }

    /** The carried portion, or zero once it has lapsed — used when recomputing a total. */
    private function liveCarriedDays(LeaveBalance $balance, int $year): float
    {
        $expiry = $balance->carried_expires_on;

        if ($expiry && $expiry->toDateString() <= Carbon::now(config('app.timezone_local'))->toDateString()) {
            return 0.0;
        }

        return (float) $balance->carried_days;
    }

    /** A computed entitlement can never fall below what has already been taken. */
    private function flooredTotal(float $computed, float $used): float
    {
        return round(max($computed, $used), 1);
    }

    /** decimal(5,1) comparison — string/float mixing here would rewrite rows on every run. */
    private function same(mixed $a, float $b): bool
    {
        return abs((float) $a - $b) < 0.05;
    }
}
