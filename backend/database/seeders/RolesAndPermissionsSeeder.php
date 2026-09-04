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
        // Member task proposals: `.propose` submits work for review (members),
        // `.approve` reviews it (PM/Admin). Both are listed here so the manager
        // tier and super_admin receive them with the rest of the projects set.
        $projectsPermissions[] = 'projects.tasks.propose';
        $projectsPermissions[] = 'projects.subtasks.propose';
        $projectsPermissions[] = 'projects.tasks.approve';
        $projectsPermissions[] = 'projects.subtasks.approve';
        // Taking an unassigned task off the open pool. Separate from `.update` so
        // a member can own work without being able to edit anyone else's.
        $projectsPermissions[] = 'projects.tasks.claim';
        // Workload Analysis: everyone may read it (scoped by role in the service);
        // retuning the platform-wide capacity bands is a management action.
        $projectsPermissions[] = 'projects.workload.view';
        $projectsPermissions[] = 'projects.workload.manage_capacity';

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
            // Members may submit their own tasks/sub-tasks for PM review. They
            // deliberately have NO .create/.update — a proposal only becomes real
            // work once a PM/Admin approves it.
            'projects.tasks.propose',
            'projects.subtasks.propose',
            // ...and they may take an open task off the pool. An unassigned task
            // commits nobody, so posting and claiming one needs no review.
            'projects.tasks.claim',
            // Members see the analysis page too, scoped to their own work.
            'projects.workload.view',
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
            // Lead Attachments — upload/delete a file on a lead. Listing and downloading are NOT
            // permissions: they reuse `view` on the lead, the same ownership boundary as opening it.
            'crm.lead.attachments.create', 'crm.lead.attachments.delete',
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
        // No export, no delete, no reports, no user management. Deliberately NO Business Rules access.
        $crmBd->syncPermissions([
            'crm.leads.view', 'crm.leads.create', 'crm.leads.edit', 'crm.leads.import',
            // A BD qualifies discovered prospects (Nearby Clients) into their own CRM pipeline.
            'crm.leads.qualify',
            // BD closes their own deals (Mark as Won).
            'crm.deals.create',
            'crm.lead.notes.create', 'crm.lead.notes.edit', 'crm.lead.notes.delete',
            // A BD attaches documents to their own leads (proposals, quotations, permits) and can
            // remove ones they uploaded — LeadAttachmentPolicy restricts delete to uploader/Admin.
            'crm.lead.attachments.create', 'crm.lead.attachments.delete',
            'crm.follow-ups.view', 'crm.follow-ups.create', 'crm.follow-ups.edit',
            'crm.calendar.view', 'crm.calendar.create', 'crm.calendar.edit', 'crm.calendar.delete',
            // A BD runs the Calling Workspace — logs calls and reads their own leads' Call History.
            'crm.calls.view', 'crm.calls.log',
            'crm.dashboard.view',
        ]);

        /*
         * The Accounting, Inventory and Booking blocks lived here -- their roles and
         * permissions, ~90 lines of them. Those modules are Kiplo's product and were
         * removed from this repo on 2026-09-03, and a role nothing can hold is a role
         * that only shows up in a picker to confuse whoever reads it.
         *
         * They stayed in this seeder rather than in the modules on the argument that
         * roles are a shared platform concern. That argument was right about WHERE
         * they live and wrong about WHOSE they are, which is why removing a module
         * left them behind.
         */

        // --- Kiosk (internal website configurator — BD lead-gen tool) ---
        // Not a sold product, so it's gated by these permissions, not an org
        // entitlement. `configure` = run the wizard + save a selection;
        // `selections.*` = see/manage saved leads + issue temp client access.
        $kioskPermissions = ['kiosk.configure', 'kiosk.selections.view', 'kiosk.selections.manage'];
        foreach ($kioskPermissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $kioskBd = Role::firstOrCreate(['name' => 'kiosk_bd', 'guard_name' => 'web']);        // BD — full kiosk
        $kioskClient = Role::firstOrCreate(['name' => 'kiosk_client', 'guard_name' => 'web']); // Temp client — configure only
        $kioskBd->syncPermissions($kioskPermissions);
        $kioskClient->syncPermissions(['kiosk.configure']);
        $superAdmin->givePermissionTo($kioskPermissions);

        // --- Business Rules Administration (Settings → Business Rules) ---
        // Two permissions: `.view` (read rules + version history + audit history) and `.manage`
        // (edit / enable-disable / reorder / publish / rollback / discard — a superset of `.view`).
        // Kept OUT of the shared $crmPermissions so the grant to each role is explicit and the temporary
        // Admin grant below is a single, easily-revertible line. Production owner: super_admin.
        $businessRulesPermissions = ['crm.business_rules.view', 'crm.business_rules.manage'];
        foreach ($businessRulesPermissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $superAdmin->givePermissionTo($businessRulesPermissions);

        // TEMPORARY — development/testing only. Granted to Admin (crm_admin) solely because no super_admin
        // account is available during development. This is the single, clearly-marked grant to REMOVE OR
        // NARROW BEFORE PRODUCTION (added after crm_admin's syncPermissions so it is additive). crm_bd and
        // the Business Development Manager get no access.
        $crmAdmin->givePermissionTo($businessRulesPermissions);

        // --- Performance Management (Reports & Performance → Manage Performance) ---
        // Goal Management CRUD + KPI-definition mutations. Held by SUPER ADMIN and ADMIN
        // (PO decision: performance management is a management capability, not an owner-only one).
        // crm_bd is deliberately excluded — a BD reads their own performance and configures nothing.
        //
        // It remains a permission SEPARATE from crm.business_rules.manage even though both roles now
        // hold both: they are different capabilities, and keeping them distinct means Lead Scoring
        // administration and Performance management can be narrowed independently later — in
        // particular when the temporary Admin grant above is revisited before production.
        $performancePermission = 'crm.performance.manage';
        Permission::firstOrCreate(['name' => $performancePermission, 'guard_name' => 'web']);
        $superAdmin->givePermissionTo($performancePermission);
        $crmAdmin->givePermissionTo($performancePermission);

        // Demo login users + their employee records are created by the HR module's
        // EmployeeDemoSeeder (via EmployeeService, so they also get leave balances).
    }
}
