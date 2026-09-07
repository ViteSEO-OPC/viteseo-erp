<?php

namespace Database\Seeders;

use App\Modules\Hr\Database\Seeders\AttendanceDemoSeeder;
use App\Modules\Hr\Database\Seeders\AttritionDemoSeeder;
use App\Modules\Hr\Database\Seeders\AuditDemoSeeder;
use App\Modules\Hr\Database\Seeders\BenefitProgramSeeder;
use App\Modules\Hr\Database\Seeders\ConcernsDemoSeeder;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Database\Seeders\FaqDemoSeeder;
use App\Modules\Hr\Database\Seeders\HeadcountMovementDemoSeeder;
use App\Modules\Hr\Database\Seeders\HealthSafetyDemoSeeder;
use App\Modules\Hr\Database\Seeders\HolidayDemoSeeder;
use App\Modules\Hr\Database\Seeders\OfficeExpenseDemoSeeder;
use App\Modules\Hr\Database\Seeders\DepartmentSeeder;
use App\Modules\Hr\Database\Seeders\DiversityDemoSeeder;
use App\Modules\Hr\Database\Seeders\EmployeeDemoSeeder;
use App\Modules\Hr\Database\Seeders\LeaveDemoSeeder;
use App\Modules\Hr\Database\Seeders\LeaveReportDemoSeeder;
use App\Modules\Hr\Database\Seeders\LeaveTypeAllotmentSeeder;
use App\Modules\Hr\Database\Seeders\LeaveTypesSeeder;
use App\Modules\Hr\Database\Seeders\PayrollDemoSeeder;
use App\Modules\Hr\Database\Seeders\RecruitmentDemoSeeder;
use App\Modules\Hr\Database\Seeders\ReimbursementDemoSeeder;
use App\Modules\Hr\Database\Seeders\RequestsDemoSeeder;
use App\Modules\Hr\Database\Seeders\WorkScheduleSeeder;
use App\Modules\Crm\Database\Seeders\CrmDemoSeeder;
use App\Modules\Crm\Database\Seeders\EmailTemplateSeeder;
use App\Modules\Projects\Database\Seeders\ProjectsDemoSeeder;
use App\Models\Org;
use App\Models\Subscription;
use App\Support\CurrentOrg;
use App\Support\Entitlements;
use App\Support\SystemRegistry;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /*
     * `WithoutModelEvents` IS DELIBERATELY NOT USED, and this is not an oversight.
     *
     * BelongsToOrg stamps org_id from the `creating` MODEL EVENT. Muting model
     * events mutes the stamp -- and the global scope, which is a query concern, is
     * NOT muted -- so every seeded row went in with a null org_id and died on the
     * NOT NULL column. The SQL said so plainly: `insert into crm_email_templates
     * ("purpose", "tone", ...)` with no org_id column at all.
     *
     * The trade-off it was there for is that observers now fire while seeding. That
     * is the correct behaviour for a tenant-scoped schema: the thing the observers
     * do includes deciding which tenant a row belongs to.
     */

    /**
     * Seed the application's database.
     *
     * Order matters: roles/permissions → departments + leave types (referenced
     * by employees) → demo employees (which create users + balances).
     */
    public function run(): void
    {
        /*
         * THE PROVIDER ORG, FIRST AND BEFORE ANYTHING ELSE.
         *
         * Every demo row below is now tenant-scoped, and BelongsToOrg stamps
         * whatever CurrentOrg::id() says. With no authenticated user that resolves
         * to the FIRST ORG -- and if no org exists it resolves to null, so every
         * insert dies on the NOT NULL column. Which is exactly what happened the
         * first time this ran after the tenancy migrations: EmailTemplateSeeder,
         * the second seeder in the list, could not write a single row.
         *
         * `firstOrCreate` rather than `create`: this runs on databases that already
         * have the provider org (a plain `db:seed` on a live dev box), and minting a
         * second one would silently split the demo data across two tenants.
         */
        $provider = Org::firstOrCreate(
            ['name' => 'Vite SEO Digital Advertising OPC'],
            ['is_active' => true],
        );

        /*
         * THE PROVIDER'S OWN SUBSCRIPTION AND ENTITLEMENTS.
         *
         * ViteSEO is a tenant of its own platform: its staff log in through the same
         * gates its clients do. Those gates are `subscription-active` -- which
         * refuses an org with NO subscription, not merely a lapsed one -- and
         * `feature:<module>`, which refuses a module the org never availed.
         *
         * Without this the provider org has neither, so the moment the route gates
         * went on, every demo login (hr@, crm.admin@, employee@) would have been
         * answered 402. Seeding it is not a convenience: it is the row that says
         * this org is entitled to the software it is looking at.
         *
         * Full tier on every system the platform sells -- read off SystemRegistry,
         * never listed by hand, so the next system to go on sale does not have to
         * remember to come back here. ViteSEO uses its own products.
         */
        $features = [];
        foreach (SystemRegistry::keys() as $system) {
            $features = [...$features, ...SystemRegistry::registry($system)::get('full')['features']];
        }
        Entitlements::sync($provider->id, array_values(array_unique($features)));

        Subscription::firstOrCreate(
            ['org_id' => $provider->id],
            [
                'plan' => 'full',
                'monthly_price' => 0,
                // NOT NULL with a default of 10; the provider's own staff exceed that.
                'seat_limit' => 500,
                'status' => Subscription::ACTIVE,
                // Far enough out that a demo box is never paywalled mid-walkthrough.
                'current_period_end' => now()->addYears(5),
            ],
        );

        /*
         * Every seeder below runs INSIDE the provider org, said explicitly.
         *
         * It used to rely on CurrentOrg falling back to the first org when nobody is
         * authenticated. That fallback is gone: with no user the answer is now null,
         * because a background path that guesses a tenant and gets it wrong is
         * indistinguishable from one that got it right. Seeding is exactly such a
         * path -- there is no request and no user -- so it has to name the org it
         * means rather than be handed one.
         */
        CurrentOrg::runAs($provider->id, function (): void {
            $this->call([
                RolesAndPermissionsSeeder::class,
                // CRM (Zerem) — default Purpose+Tone email templates (content config, idempotent).
                EmailTemplateSeeder::class,
                // CRM (Zerem) — demo pipeline (leads/prospects/deals/planner/outreach) + the CRM demo
                // logins. Needs the roles above; independent of the HR seeders. Idempotent.
                CrmDemoSeeder::class,
                DepartmentSeeder::class,
                LeaveTypesSeeder::class,
                // Config defaults must exist before employees are created — new hires
                // seed their balances/benefits from these (EmployeeService::create).
                LeaveTypeAllotmentSeeder::class,
                ContributionSettingSeeder::class,
                WorkScheduleSeeder::class,
                EmployeeDemoSeeder::class,
                // Real joiner/leaver records so the Headcount & Movement report has data.
                HeadcountMovementDemoSeeder::class,
                // Typed departures across quarters for the Attrition & Tenure report.
                AttritionDemoSeeder::class,
                // Gender values for the Diversity & Pay Equity representation chart.
                DiversityDemoSeeder::class,
                // Benefit programs enrol every employee, so seed after employees exist.
                BenefitProgramSeeder::class,
                LeaveDemoSeeder::class,
                // Approved leave history across months for the Absences & Leaves report.
                LeaveReportDemoSeeder::class,
                AttendanceDemoSeeder::class,
                ReimbursementDemoSeeder::class,
                PayrollDemoSeeder::class,
                RecruitmentDemoSeeder::class,
                RequestsDemoSeeder::class,
                ConcernsDemoSeeder::class,
                // Safety concerns across months for the Health & Safety report.
                HealthSafetyDemoSeeder::class,
                OfficeExpenseDemoSeeder::class,
                FaqDemoSeeder::class,
                HolidayDemoSeeder::class,
                // Backfill representative audit-trail history for the Audit report.
                AuditDemoSeeder::class,
                // PMS (Projects) demo: reuses the HR users, grants PMS roles, and
                // builds a workspace with projects/groups/tasks/subtasks/etc.
                ProjectsDemoSeeder::class,
            ]);
        });

        /*
         * DEMO LOGINS.
         *
         * EmployeeService::create is right to refuse a guessable default -- when no
         * password is supplied it mints 40 random characters, so a real hire cannot be
         * logged into by anyone who reads the code. Its own comment says "demo logins
         * come from seeders, which pass an explicit password".
         *
         * This seeder did not pass one. So every seeded account -- hr@, crm.admin@,
         * employee@ -- had a random password nobody knew, and the documented demo
         * logins could not be used at all. Set here, after everything is created, and
         * ONLY here: this file is run by hand on a dev box, never in production.
         */
        foreach (User::query()->where('email', 'like', '%@viteseo.ph')->cursor() as $user) {
            $user->forceFill(['password' => 'password'])->save();
        }

        /*
         * MEMBERSHIP. Every seeded user belongs to the provider org.
         *
         * Nothing in the seed path was writing org_user: memberships are created by
         * OrgOnboardingService and by the TeamControllers, and the demo employees come
         * from EmployeeService instead. Before tenancy that was invisible. After it,
         * CurrentOrg::id() FAILS CLOSED for a user with no membership -- it returns
         * null rather than guessing the first org -- so a seeded user could not even
         * log in: the login writes an audit row and the insert died on a null org_id.
         * Had it got past that, every scoped query would have returned nothing.
         *
         * The platform super admin is excluded on purpose: a membership would pin them
         * to one tenant, and seeing across all of them is the job.
         */
        $providerId = $provider->id;
        User::query()
            ->where('email', 'like', '%@viteseo.ph')
            ->where('email', '!=', 'platform@viteseo.ph')
            ->whereDoesntHave('orgs')
            ->cursor()
            ->each(function (User $user) use ($providerId): void {
                $user->orgs()->attach($providerId, ['is_default' => true]);
            });

        /*
         * THE PLATFORM SUPER ADMIN -- ViteSEO's own account, not a client's.
         *
         * This is the one who sees billing and onboarding across every tenant and
         * who mints a client's org and that org's first admin. The role existed and
         * NOBODY HELD IT: `platform:assign-roles` grants it to a named production
         * address that does not exist on a local box, so there was no way to open
         * the admin surfaces at all while building them.
         *
         * Created AFTER the seeder list because RolesAndPermissionsSeeder is IN
         * that list -- assigning a role before the role exists silently assigns
         * nothing, which is what happened on the first attempt.
         *
         * Deliberately NOT attached to any org. CurrentOrg::id() falls back to the
         * first org for a super_admin precisely so the provider surfaces work, and
         * an org membership would pin them to one tenant -- the opposite of the job.
         */
        User::firstOrCreate(
            ['email' => 'platform@viteseo.ph'],
            ['name' => 'ViteSEO Platform Admin', 'password' => 'password'],
        )->syncRoles(['super_admin']);

    }
}
