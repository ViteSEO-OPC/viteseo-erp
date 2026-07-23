<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Core seeder: roles, HR permissions, and the demo login users.
 *
 * Roles/permissions are shared platform concerns (Spatie), so this lives in the
 * base database/seeders — not the HR module. Full employee records + leave
 * balances are seeded later (Slice 4's DemoDataSeeder); here we only need
 * logins with roles so auth + portal routing can be tested.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Spatie caches permissions; clear it so re-seeding sees a clean slate.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // --- Permissions (named hr.<resource>.<action> per the standard) ---
        $permissions = [
            'hr.departments.view', 'hr.departments.create', 'hr.departments.update', 'hr.departments.delete',
            'hr.employees.view', 'hr.employees.create', 'hr.employees.update',
            'hr.leave_types.view',
            'hr.leave_balances.view',
            'hr.leave_requests.view', 'hr.leave_requests.create', 'hr.leave_requests.approve',
            'hr.attendance.view',
            'hr.reimbursements.view', 'hr.reimbursements.create', 'hr.reimbursements.approve',
            'hr.dashboard.view',
            // Phase E (extended slices).
            'hr.payroll.view', 'hr.payroll.run', 'hr.payslips.view',
            'hr.recruitment.view', 'hr.recruitment.manage',
            'hr.reports.view',
            // Phase 2 — Company config (HR/Admin only).
            'hr.leave_types.create', 'hr.leave_types.update', 'hr.leave_types.delete',
            'hr.leave_allotments.view', 'hr.leave_allotments.manage',
            'hr.benefits.view', 'hr.benefits.manage',
            'hr.rules.view', 'hr.rules.manage',
            'hr.violations.view', 'hr.violations.manage',
            'hr.document_requests.view', 'hr.document_requests.manage',
            'hr.change_requests.view', 'hr.change_requests.manage',
            'hr.payslip_requests.view', 'hr.payslip_requests.manage',
            'hr.payroll_inquiries.view', 'hr.payroll_inquiries.manage',
            'hr.concerns.view', 'hr.concerns.manage',
            'hr.office_expenses.view', 'hr.office_expenses.manage',
            'hr.faqs.view', 'hr.faqs.manage',
            'hr.holidays.view', 'hr.holidays.manage',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // --- Projects (PMS) permissions (namespaced projects.<resource>.<action>) ---
        // Mirrors the module's PermissionSeeder; kept here because roles/permissions
        // are a shared platform concern, not per-module.
        $projectsCrud = ['workspaces', 'groups', 'projects', 'statuses', 'tasks', 'subtasks'];
        $projectsAppendOnly = ['comments', 'links'];

        $projectsPermissions = [];
        foreach ($projectsCrud as $resource) {
            foreach (['view', 'create', 'update', 'delete'] as $action) {
                $projectsPermissions[] = "projects.{$resource}.{$action}";
            }
        }
        foreach ($projectsAppendOnly as $resource) {
            foreach (['view', 'create', 'delete'] as $action) {
                $projectsPermissions[] = "projects.{$resource}.{$action}";
            }
        }
        $projectsPermissions[] = 'projects.activity_logs.view';
        $projectsPermissions[] = 'projects.groups.manage_members';
        $projectsPermissions[] = 'projects.projects.manage_members';

        foreach ($projectsPermissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // Member-level (read + contribute) subset for regular members.
        $projectsMemberPermissions = [
            'projects.workspaces.view',
            'projects.groups.view',
            'projects.projects.view',
            'projects.statuses.view',
            'projects.tasks.view',
            'projects.subtasks.view',
            'projects.comments.view', 'projects.comments.create',
            'projects.links.view', 'projects.links.create',
            'projects.activity_logs.view',
        ];

        // HRIS self-service subset (view own payslip, file leave, clock in, etc.) — the
        // base HRIS access granted to every employee.
        $hrEmployeePermissions = [
            'hr.departments.view',
            'hr.employees.view',
            'hr.leave_types.view',
            'hr.leave_balances.view',
            'hr.leave_requests.view', 'hr.leave_requests.create',
            'hr.attendance.view',
            'hr.reimbursements.view', 'hr.reimbursements.create',
            'hr.dashboard.view',
            'hr.payslips.view',
        ];

        // --- Per-system roles (7) ---
        // Explicit per-module roles so access is unambiguous (a person can be CRM admin
        // but a plain HRIS employee). super_admin covers the top of every module — there
        // is intentionally NO hr_admin / crm_super_admin / pm_admin.
        $superAdmin = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);  // ALL modules
        $hrOfficer = Role::firstOrCreate(['name' => 'hr_officer', 'guard_name' => 'web']);    // HRIS management
        $hrEmployee = Role::firstOrCreate(['name' => 'hr_employee', 'guard_name' => 'web']);  // HRIS self-service
        $crmAdmin = Role::firstOrCreate(['name' => 'crm_admin', 'guard_name' => 'web']);      // CRM manager
        $crmBd = Role::firstOrCreate(['name' => 'crm_bd', 'guard_name' => 'web']);            // CRM business-dev
        $pmApm = Role::firstOrCreate(['name' => 'pm_apm', 'guard_name' => 'web']);            // PMS management
        $pmMember = Role::firstOrCreate(['name' => 'pm_member', 'guard_name' => 'web']);      // PMS member

        // hr_officer → all HR permissions (HRIS management).
        $hrOfficer->syncPermissions($permissions);

        // hr_employee → HRIS self-service subset (base HRIS access).
        $hrEmployee->syncPermissions($hrEmployeePermissions);

        // pm_apm (PMS management) → full projects access.
        $pmApm->syncPermissions($projectsPermissions);

        // pm_member (PMS member) → read + contribute on projects.
        $pmMember->syncPermissions($projectsMemberPermissions);

        // super_admin (CEO) — all-access platform role: every HR + projects (+ CRM below) perm.
        $superAdmin->syncPermissions($permissions);
        $superAdmin->givePermissionTo($projectsPermissions);

        // --- CRM (Zerem) permissions (namespaced crm.*) ---
        // Mirrors Zerem's DatabaseSeeder permission list, namespaced under `crm.`
        // and mapped onto the per-system platform roles (super-admin→super_admin,
        // CRM-manager→crm_admin, business-development-manager→crm_bd). Roles/permissions
        // are a shared platform concern, so they live here — not in the module.
        $crmPermissions = [
            'crm.leads.view', 'crm.leads.create', 'crm.leads.edit', 'crm.leads.delete',
            'crm.leads.import', 'crm.leads.export', 'crm.leads.assign',
            // Archive Management — restore a soft-deleted ("archived") lead. Management-only
            // (Admin/Super Admin), mirroring leads.delete: BDs never archive or restore.
            'crm.leads.restore',
            // Qualify a discovered prospect (Nearby Clients) into the CRM sales pipeline. The bridge
            // from Prospect Pool → Active CRM Lead. BD self-service (BD/Admin/Super Admin).
            'crm.leads.qualify',
            // Deal bounded context — creating a Deal ("Mark as Won"). BD sales workflow.
            'crm.deals.create',
            'crm.lead.notes.create', 'crm.lead.notes.edit', 'crm.lead.notes.delete',
            'crm.follow-ups.view', 'crm.follow-ups.create', 'crm.follow-ups.edit',
            'crm.reports.view', 'crm.reports.export',
            // Planner (module branded "Planner"; permission namespace stays `calendar`).
            'crm.calendar.view', 'crm.calendar.create', 'crm.calendar.edit', 'crm.calendar.delete',
            // Calling Workspace — read Call History (crm.calls.view) and log/conclude a call
            // (crm.calls.log). Calls are Planner-driven; see CallSessionService.
            'crm.calls.view', 'crm.calls.log',
            'crm.dashboard.view',
            'crm.users.view', 'crm.users.create', 'crm.users.edit', 'crm.users.delete',
        ];

        foreach ($crmPermissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // RC3 Outreach Automation Pack — automation-only ability. Defined so it exists on a fresh
        // install, but DELIBERATELY NOT synced to any human role: only the scoped n8n service
        // account holds it, granted by `php artisan n8n:token` from config/n8n.php. This keeps the
        // send-authorization least-privilege and off every human role.
        foreach (['crm.outreach.automation.send'] as $automationPermission) {
            Permission::firstOrCreate(['name' => $automationPermission, 'guard_name' => 'web']);
        }

        // super_admin (CEO) — unrestricted (also bypasses via Gate::before in
        // CrmServiceProvider); synced for clarity.
        $superAdmin->givePermissionTo($crmPermissions);

        // crm_admin (CRM Manager) — full CRM access EXCEPT deleting user accounts.
        $crmAdmin->syncPermissions(array_values(array_diff($crmPermissions, ['crm.users.delete'])));

        // crm_bd (Business Development Manager) — business modules + self-import.
        // No export, no delete, no reports, no user management.
        $crmBd->syncPermissions([
            'crm.leads.view', 'crm.leads.create', 'crm.leads.edit', 'crm.leads.import',
            // A BD qualifies discovered prospects (Nearby Clients) into their own CRM pipeline.
            'crm.leads.qualify',
            // BD closes their own deals (Mark as Won).
            'crm.deals.create',
            'crm.lead.notes.create', 'crm.lead.notes.edit', 'crm.lead.notes.delete',
            'crm.follow-ups.view', 'crm.follow-ups.create', 'crm.follow-ups.edit',
            'crm.calendar.view', 'crm.calendar.create', 'crm.calendar.edit', 'crm.calendar.delete',
            // A BD runs the Calling Workspace — logs calls and reads their own leads' Call History.
            'crm.calls.view', 'crm.calls.log',
            'crm.dashboard.view',
        ]);

        // Demo login users + their employee records are created by the HR module's
        // EmployeeDemoSeeder (via EmployeeService, so they also get leave balances).
    }
}
