<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Assigns the platform's per-system roles (and the HRIS `is_management` flag) to
 * the real Vite SEO Systems users, per the approved matrix below.
 *
 * Idempotent + non-creating: for each email it `syncRoles(...)` to EXACTLY the
 * listed roles and sets `is_management` on the user's linked hr_employees row.
 * It never creates users — a missing email is reported and skipped — so the
 * command is safe to re-run at any time.
 *
 * Roles referenced here must already exist (run db:seed / RolesAndPermissionsSeeder
 * first). `is_management` is a payroll/attendance attribute on the employee record,
 * NOT a role.
 */
class AssignPlatformRoles extends Command
{
    protected $signature = 'platform:assign-roles {--dry-run : Show what would change without writing}';

    protected $description = 'Assign per-system roles + the is_management flag to the real platform users (idempotent, non-creating)';

    /**
     * The approved assignment matrix: email => [roles, management].
     * `roles` are synced exactly (removing any not listed); `management` sets the
     * employee record's is_management flag.
     *
     * @var array<string, array{roles: list<string>, management: bool}>
     */
    private const MATRIX = [
        'uriel.papa@viteseo.ph'        => ['roles' => ['super_admin'],                       'management' => false],
        'julius.santiago@viteseo.ph'   => ['roles' => ['hr_employee', 'crm_admin'],          'management' => true],
        'kenneth.aquino@viteseo.ph'    => ['roles' => ['hr_employee', 'crm_admin'],          'management' => true],
        'shandy.pacio@viteseo.ph'      => ['roles' => ['hr_officer', 'hr_employee'],         'management' => true],
        'cristina.cupas@viteseo.ph'    => ['roles' => ['hr_officer', 'hr_employee'],         'management' => true],
        'jhamire.aranda@viteseo.ph'    => ['roles' => ['hr_employee', 'crm_bd'],             'management' => true],
        'edgar.dionisio@viteseo.ph'    => ['roles' => ['hr_employee', 'pm_apm'],             'management' => false],
        'alliana.camara@viteseo.ph'    => ['roles' => ['hr_employee', 'pm_member'],          'management' => false],
        'griella.autida@viteseo.ph'    => ['roles' => ['hr_employee', 'pm_member'],          'management' => false],
        'sarah.malaras@viteseo.ph'     => ['roles' => ['hr_employee', 'pm_member'],          'management' => false],
        'adrian.panganiban@viteseo.ph' => ['roles' => ['hr_employee', 'pm_member'],          'management' => false],
        'jake.castro@viteseo.ph'       => ['roles' => ['hr_employee', 'pm_member'],          'management' => false],
        'john.villanera@viteseo.ph'    => ['roles' => ['hr_employee', 'pm_member'],          'management' => false],
        'bruce.andrada@viteseo.ph'     => ['roles' => ['hr_employee', 'crm_admin', 'pm_member'], 'management' => false],
        'joshua.reyes@viteseo.ph'      => ['roles' => ['hr_employee', 'crm_admin', 'pm_member'], 'management' => false],
        'vincent.robles@viteseo.ph'    => ['roles' => ['hr_employee', 'crm_admin', 'pm_member'], 'management' => false],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Roles resolve against the cached permission registrar; clear it so a freshly
        // seeded role set is visible in the same process.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $rows = [];
        $missing = [];
        $applied = 0;

        foreach (self::MATRIX as $email => $spec) {
            /** @var User|null $user */
            $user = User::where('email', $email)->first();

            if (! $user) {
                $missing[] = $email;
                $rows[] = [$email, '—', '—', 'MISSING (skipped)'];

                continue;
            }

            $roles = $spec['roles'];
            $management = $spec['management'];

            if ($dryRun) {
                $rows[] = [
                    $email,
                    implode(', ', $roles),
                    $management ? 'true' : 'false',
                    $user->employee ? 'would update' : 'would update (no employee row)',
                ];

                continue;
            }

            DB::transaction(function () use ($user, $roles, $management): void {
                // Exactly these roles (removes any others the user previously held).
                $user->syncRoles($roles);

                // is_management lives on the linked hr_employees row (HasOne). Only users
                // with an employee record carry the flag; others simply have their roles set.
                if ($employee = $user->employee) {
                    $employee->is_management = $management;
                    $employee->save();
                }
            });

            $applied++;
            $rows[] = [
                $email,
                implode(', ', $roles),
                $management ? 'true' : 'false',
                $user->employee ? 'updated' : 'roles set (no employee row)',
            ];
        }

        $this->table(['Email', 'Roles', 'is_management', 'Result'], $rows);

        if ($missing) {
            $this->warn(sprintf(
                '%d email(s) not found and skipped: %s',
                count($missing),
                implode(', ', $missing),
            ));
        }

        if ($dryRun) {
            $this->info('Dry run — no changes written.');
        } else {
            $this->info(sprintf('Done. %d user(s) updated, %d missing.', $applied, count($missing)));
        }

        return self::SUCCESS;
    }
}
