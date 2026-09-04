<?php

namespace App\Modules\Hr\Database\Seeders;

use App\Support\CurrentOrg;
use App\Models\User;
use App\Modules\Hr\Models\AuditLog;
use Illuminate\Database\Seeder;

/**
 * Representative audit-trail history so the Reports → Audit Trail report is
 * populated across every module and action verb. Real logging (AuditService)
 * covers going-forward activity; this backfills the demo period.
 */
class AuditDemoSeeder extends Seeder
{
    use RefusesOutsideDemoEnvironments;

    public function run(): void
    {
        if ($this->demoSeedingBlocked()) {
            return;
        }

        // Normalize any legacy login rows to the new verb/module format.
        AuditLog::where('action', 'auth.login')->update([
            'action' => 'login',
            'module' => 'Auth',
            'description' => 'Signed in',
        ]);

        // Idempotent — skip if we've already backfilled (real logins are 'login').
        if (AuditLog::where('module', 'Benefits')->exists()) {
            return;
        }

        $id = fn (string $email) => User::where('email', $email)->value('id');
        $hr = $id('hr@viteseo.ph');       // Dana Reyes (HR)
        $maya = $id('employee@viteseo.ph');
        $leo = $id('leo@viteseo.ph');
        $bea = $id('bea@viteseo.ph');
        $carlo = $id('carlo@viteseo.ph');

        // [user_id, action, module, description, daysAgo, hour, minute]
        $rows = [
            [$hr, 'assigned', 'Benefits', 'Food + Transpo allowances added — ₱3,500.00/mo', 0, 9, 42],
            [$hr, 'approved', 'Leave', "Approved Maya Villanueva's Annual Leave request (Jun 15–17)", 0, 9, 38],
            [$hr, 'updated', 'Rules', "Tardiness pay deduction mode changed to 'No deduction'", 1, 17, 15],
            [$hr, 'enrolled', 'Benefits', 'HealthFirst HMO — Self + 1 dependent', 1, 14, 30],
            [$hr, 'created', 'Leave Types', 'Service Incentive Leave (SIL) — 5.0 days/year', 1, 11, 2],
            [$maya, 'submitted', 'Leave', 'Sick Leave request (Jun 22) — 1 day', 2, 16, 45],
            [$hr, 'updated', 'Rules', 'AWOL threshold changed from 5 to 3 days', 2, 10, 20],
            [$hr, 'updated', 'Benefits', 'PhilHealth contribution updated — ₱450.00/mo', 3, 8, 55],
            [$hr, 'created', 'Schedules', "'Field' schedule — Mon–Sat, 08:00–17:00, 30 min grace", 3, 8, 30],
            [$carlo, 'submitted', 'Leave', 'Bereavement Leave request (Jun 10–12) — 3 days', 4, 15, 10],
            [$hr, 'declined', 'Reimbursement', "Declined Leo Santos's transport claim (₱850)", 5, 13, 22],
            [$hr, 'approved', 'Reimbursement', "Approved Bea Ramos's supplies claim (₱1,200)", 5, 11, 5],
            [$leo, 'submitted', 'Documents', 'Certificate of Employment request', 6, 9, 15],
            [$hr, 'approved', 'Documents', 'Fulfilled Certificate of Employment request', 6, 14, 40],
            [$maya, 'submitted', 'Profile', 'Contact number change request', 7, 10, 5],
            [$hr, 'approved', 'Profile', "Approved Maya Villanueva's contact number change", 7, 15, 12],
            [$hr, 'scheduled', 'Attendance', 'Meeting with Leo Santos about attendance', 8, 16, 0],
            [$hr, 'created', 'People', 'Added employee Grace Tan', 9, 9, 30],
            [$hr, 'updated', 'People', "Updated Maya Villanueva's compensation", 9, 10, 12],
            [$hr, 'created', 'FAQ', "Created FAQ 'How do I file for leave?'", 10, 11, 45],
            [$hr, 'updated', 'FAQ', "Published FAQ 'When is payday?'", 10, 11, 50],
            [$hr, 'created', 'Holidays', 'Loaded Philippines 2026 holidays', 12, 8, 20],
            [$hr, 'updated', 'Holidays', "Updated holiday 'Chinese New Year' type", 12, 8, 35],
            [$hr, 'created', 'Recruitment', "Opened requisition 'Senior Backend Engineer'", 14, 13, 0],
            [$hr, 'updated', 'Recruitment', 'Moved candidate to Final Interview', 15, 10, 25],
            [$hr, 'approved', 'Recruitment', 'Hired Rafael Aquino', 16, 16, 40],
            [$bea, 'submitted', 'Payroll', 'Payroll inquiry: SSS deduction higher than usual', 18, 9, 5],
            [$hr, 'resolved', 'Payroll', 'Resolved a payroll inquiry', 18, 15, 30],
            [$maya, 'submitted', 'Payroll', 'Payslip copy request', 20, 14, 10],
            [$hr, 'approved', 'Payroll', 'Approved payslip request for Maya Villanueva', 20, 16, 0],
            [$hr, 'removed', 'Leave Types', "Removed leave type 'Study Leave'", 22, 11, 15],
            [$hr, 'removed', 'Departments', "Archived department 'Logistics'", 25, 10, 0],
            [$hr, 'assigned', 'Schedules', 'Assigned schedule to Diego Ramos', 28, 9, 45],
            [$hr, 'exported', 'Reports', 'Exported the Compensation & Benefits report', 30, 17, 20],
            [$hr, 'exported', 'Reports', 'Exported the Attrition & Tenure report', 40, 16, 10],
        ];

        $entries = [];
        foreach ($rows as [$userId, $action, $module, $description, $daysAgo, $h, $m]) {
            $ts = now(config('app.timezone_local'))->subDays($daysAgo)->setTime($h, $m);
            $entries[] = [
                'user_id' => $userId,
                'action' => $action,
                'module' => $module,
                'description' => $description,
                'auditable_type' => null,
                'auditable_id' => null,
                'before' => null,
                'after' => null,
                'ip_address' => '127.0.0.1',
                'created_at' => $ts,
                'updated_at' => $ts,
            ];
        }

        /*
         * org_id is set by hand here because Model::insert() is a QUERY BUILDER call:
         * it never makes a model, so BelongsToOrg's `creating` stamp never runs. The
         * global scope is a read-side concern and does not cover this either. Bulk
         * insert is the one write path in this codebase that has to remember the
         * tenant for itself.
         */
        $orgId = CurrentOrg::id();
        $entries = array_map(static function (array $e) use ($orgId): array {
            $e['org_id'] = $orgId;

            return $e;
        }, $entries);

        AuditLog::insert($entries);
    }
}
