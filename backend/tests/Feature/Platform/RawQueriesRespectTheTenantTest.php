<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Org;
use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\Niche;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\ReportService;
use App\Support\CurrentOrg;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The global scope only fences ELOQUENT. A raw query is outside it.
 *
 * `BelongsToOrg` adds `where org_id = ?` to a model's query builder, and 101 models
 * carry it — which makes it very easy to believe the tenant fence is everywhere. It
 * is not. `DB::table('hr_employees')` has no model, so it has no scope, so it reads
 * every tenant's rows and says nothing about it. The HR Reports hub was built almost
 * entirely out of those, and a rename in the CRM niche registry issued a raw UPDATE
 * across every tenant's leads.
 *
 * These tests are deliberately built with two REAL orgs and read through the same
 * service the controller calls, because that is the only way the assertion means
 * "the report a client sees" rather than "this query has a where clause".
 */
class RawQueriesRespectTheTenantTest extends TestCase
{
    use RefreshDatabase;

    private function orgNamed(string $name): Org
    {
        return Org::create(['name' => $name, 'is_active' => true]);
    }

    /** An employee in `$org`, in a department of its own. */
    private function employeeIn(Org $org, string $tag): Employee
    {
        return CurrentOrg::runAs($org->id, function () use ($tag): Employee {
            $user = User::create([
                'name' => "Person {$tag}",
                'email' => "{$tag}@client.test",
                'password' => 'password-123',
            ]);

            $department = Department::create([
                'name' => "Dept {$tag}",
                'code' => "D-{$tag}",
                'is_active' => true,
            ]);

            return Employee::create([
                'user_id' => $user->id,
                'department_id' => $department->id,
                'employee_no' => "E-{$tag}",
                'job_title' => 'Staff',
                'hire_date' => '2026-01-15',
            ]);
        });
    }

    public function test_the_headcount_report_counts_only_the_reading_tenant(): void
    {
        $a = $this->orgNamed('Alpha');
        $b = $this->orgNamed('Beta');

        $this->employeeIn($a, 'a1');
        $this->employeeIn($b, 'b1');
        $this->employeeIn($b, 'b2');

        $report = CurrentOrg::runAs($a->id, fn () => app(ReportService::class)->get('headcount'));

        $total = collect($report['summary'])->firstWhere('label', 'Total headcount')['value'];

        // Eloquent already got this right (`Employee::count()`), which is precisely
        // what made the bar chart beside it so convincing.
        $this->assertSame('1', $total);

        // The bar chart is `DB::table('hr_employees')->join('hr_departments')`. It
        // showed Alpha a row per Beta department, with Beta's headcount in it.
        $bars = collect($report['bars']['items'])->pluck('label')->all();
        $this->assertSame(['Dept a1'], $bars);
    }

    public function test_the_department_picker_offers_only_the_reading_tenants_departments(): void
    {
        $a = $this->orgNamed('Alpha');
        $b = $this->orgNamed('Beta');

        $this->employeeIn($a, 'a1');
        $this->employeeIn($b, 'b1');

        // Ten reports render this same picker off one raw query. The filter it feeds
        // is a department id, so leaking the list also hands over usable ids.
        // A representative spread of the ten. (`leave` and `health_safety` are left
        // out only because their aggregates are MySQL-specific and will not run on the
        // sqlite test connection -- they render the same picker from the same helper.)
        foreach (['headcount', 'attrition', 'recruitment', 'diversity', 'leave_liability'] as $key) {
            $names = collect(
                CurrentOrg::runAs($a->id, fn () => app(ReportService::class)->get($key))['departments']
            )->pluck('name')->all();

            $this->assertSame(['Dept a1'], $names, "The {$key} report offered another tenant's departments.");
        }
    }

    public function test_renaming_a_niche_cannot_rewrite_another_tenants_leads(): void
    {
        $a = $this->orgNamed('Alpha');
        $b = $this->orgNamed('Beta');

        $make = function (Org $org, string $name): Lead {
            return CurrentOrg::runAs($org->id, function () use ($name): Lead {
                $owner = User::create([
                    'name' => "Owner {$name}",
                    'email' => strtolower(str_replace(' ', '-', $name)).'@client.test',
                    'password' => 'password-123',
                ]);

                return Lead::create([
                    'business_name' => $name,
                    'contact_name' => 'Contact',
                    'industry' => 'Pharmacy',
                    'created_by' => $owner->id,
                ]);
            });
        };

        $ours = $make($a, 'Alpha Pharmacy');
        $theirs = $make($b, 'Beta Pharmacy');

        // Alpha renames its own niche. This issues a raw UPDATE on crm_leads.
        CurrentOrg::runAs($a->id, fn () => Niche::repointLeads('Pharmacy', 'Drugstore'));

        $this->assertSame('Drugstore', $ours->fresh()->industry);

        // Not a read leak — a WRITE into a tenant that was never touched. Beta's
        // niche registry still says "Pharmacy" and its leads now disagree with it.
        $this->assertSame(
            'Pharmacy',
            CurrentOrg::runAs($b->id, fn () => $theirs->fresh()->industry),
            "Alpha's rename rewrote Beta's leads.",
        );
    }

    public function test_thirteenth_month_accrual_covers_only_the_reading_tenants_staff(): void
    {
        $a = $this->orgNamed('Alpha');
        $b = $this->orgNamed('Beta');

        $ours = $this->employeeIn($a, 'a1');
        $theirs = $this->employeeIn($b, 'b1');

        $paid = function (Org $org, Employee $employee): void {
            CurrentOrg::runAs($org->id, function () use ($employee): void {
                $run = PayrollRun::create([
                    'cycle_start' => '2026-01-01',
                    'cycle_end' => '2026-01-31',
                    'type' => 'regular',
                    'status' => 'completed',
                ]);

                Payslip::create([
                    'payroll_run_id' => $run->id,
                    'employee_id' => $employee->id,
                    'base_salary' => 30000,
                    'gross' => 30000,
                    'net_pay' => 30000,
                ]);
            });
        };

        $paid($a, $ours);
        $paid($b, $theirs);

        // The accrual report and the 13th-month RUN share one query builder, so this
        // is not only a report leak: the payment is computed off the same rows. The
        // basis is `DB::table('hr_payslips')` with no employee filter -- unfenced, it
        // measured every tenant's payroll and would pay Beta's staff out of Alpha's run.
        $accrual = CurrentOrg::runAs($a->id, fn () => app(PayrollService::class)->thirteenthMonth(2026));

        $this->assertSame(1, $accrual['count'], "Alpha's 13th-month accrual included another tenant's staff.");
        $this->assertSame(
            [$ours->id],
            array_map(static fn (array $row): int => (int) $row['employee_id'], $accrual['rows']),
        );
    }
}
