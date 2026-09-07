<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\CustomRole;
use App\Support\CapabilityRegistry;
use App\Support\CurrentOrg;
use App\Support\Entitlements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Support\FeatureRegistry;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Custom Roles (v2). A client org's own admin defines that org's roles -- each a
 * name plus a set of capabilities -- and a member holding one gets exactly those
 * permissions. Everything is org-scoped.
 *
 * BOUNDED BY WHAT THE ORG AVAILED, 2026-09-03. Until now this offered a hardcoded
 * list of twelve ACCOUNTING permissions, which made it useless to an HRIS, CRM or
 * Projects customer and, worse, made "custom role" mean "any accounting power"
 * regardless of plan. The catalog now comes from CapabilityRegistry, which reads
 * the live permissions and then removes everything the org has no entitlement to.
 *
 * The filter is applied TWICE on purpose. `capabilities()` shapes the picker, and
 * `validated()` refuses on write -- because a picker is a suggestion and a request
 * body can name any permission string it likes. An org on CRM Basic that posts
 * `crm.outreach.send` is told no, not quietly granted it.
 */
class CustomRoleController extends Controller
{
    /**
     * The accounting preset labels, kept because `presets()` still describes the
     * three built-in Accounting roles in the operator's words rather than the
     * derived ones. NOT the grantable set any more -- see CapabilityRegistry.
     *
     * @var array<string, string>
     */
    public const CAPABILITIES = [
        'accounting.accounts.view' => 'View chart of accounts',
        'accounting.accounts.create' => 'Create accounts',
        'accounting.accounts.update' => 'Edit accounts',
        'accounting.accounts.delete' => 'Delete accounts',
        'accounting.journal.view' => 'View journal, ledgers & reports data',
        'accounting.journal.create' => 'Create entries, invoices & bills',
        'accounting.journal.post' => 'Post entries',
        'accounting.journal.reverse' => 'Reverse entries',
        'accounting.budgets.view' => 'View budgets',
        'accounting.budgets.manage' => 'Manage budgets',
        'accounting.reports.view' => 'View reports',
        'accounting.payroll.post' => 'Post payroll to the ledger',
    ];

    /**
     * Who may build roles for an org.
     *
     * `super_admin` is the org's own super admin -- the account the platform mints
     * for a client at onboarding. The module admins are here because each already
     * administers its own staff through its TeamController, and a role builder that
     * only an accounting admin could reach is no use to a client who bought HRIS.
     *
     * @var list<string>
     */
    private const ROLE_BUILDERS = ['super_admin', 'acc_admin', 'hr_officer', 'crm_admin', 'pm_apm'];

    private function ensureAdmin(Request $request): void
    {
        abort_unless($request->user()->hasAnyRole(self::ROLE_BUILDERS), 403);
    }

    /**
     * GET /api/v1/capabilities — what THIS org may put on a role.
     *
     * Grouped by module and by the feature that sells it, so the builder can show
     * "Payroll" as a block the client recognises from what they bought rather than
     * as forty loose permission strings.
     */
    public function capabilities(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $caps = CapabilityRegistry::forOrg(CurrentOrg::id());

        $grouped = [];
        foreach ($caps as $c) {
            $grouped[$c['feature']][] = ['key' => $c['key'], 'label' => $c['label'], 'group' => $c['group']];
        }

        $data = [];
        foreach ($grouped as $feature => $items) {
            $data[] = [
                'feature' => $feature,
                'label' => FeatureRegistry::label($feature) ?? $feature,
                'capabilities' => $items,
            ];
        }

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/v1/roles/presets — the built-in roles (Finance Admin / Accountant
     * / Viewer) with what each can do. Read-only reference so an admin knows what
     * a preset grants before assigning it. Reads the live spatie permissions.
     */
    /**
     * The PRESET roles for the systems this org availed, with what each really grants.
     *
     * Read-only reference, so an admin can see what a preset does before assigning it
     * instead of guessing from its name.
     *
     * This used to name three ACCOUNTING roles and filter their permissions to
     * `accounting.` -- none of which exist in this deployment, so every client saw
     * three empty role names and learned nothing. The presets are per-module, so the
     * list is derived from what the org actually bought: an org on CRM alone has no
     * business reading HR's role definitions.
     *
     * Permissions come off the LIVE role rather than a hardcoded list, so a preset
     * that gains an ability in the seeder gains it here too.
     */
    public function presets(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        /** @var array<string, array{label: string, feature: string, namespace: string}> */
        $presets = [
            'hr_officer' => ['label' => 'HR Officer', 'feature' => 'hr', 'namespace' => 'hr.'],
            'hr_employee' => ['label' => 'Employee', 'feature' => 'hr', 'namespace' => 'hr.'],
            'crm_admin' => ['label' => 'CRM Manager', 'feature' => 'crm', 'namespace' => 'crm.'],
            'crm_bd' => ['label' => 'Business Development', 'feature' => 'crm', 'namespace' => 'crm.'],
            'pm_apm' => ['label' => 'Project Manager', 'feature' => 'pms', 'namespace' => 'projects.'],
            'pm_member' => ['label' => 'Team Member', 'feature' => 'pms', 'namespace' => 'projects.'],
            'acc_admin' => ['label' => 'Finance Admin', 'feature' => 'accounting', 'namespace' => 'accounting.'],
            'acc_accountant' => ['label' => 'Accountant', 'feature' => 'accounting', 'namespace' => 'accounting.'],
            'acc_viewer' => ['label' => 'Viewer / Auditor', 'feature' => 'accounting', 'namespace' => 'accounting.'],
        ];

        $entitled = Entitlements::keysFor(CurrentOrg::id());
        $labels = collect(CapabilityRegistry::all())->keyBy('key');

        $data = [];
        foreach ($presets as $name => $meta) {
            if (! in_array($meta['feature'], $entitled, true)) {
                continue;
            }

            $role = Role::where('name', $name)->where('guard_name', 'web')->first();
            $perms = $role
                ? $role->permissions->pluck('name')->filter(
                    fn (string $p): bool => str_starts_with($p, $meta['namespace'])
                )->values()
                : collect();

            $data[] = [
                'key' => $name,
                'label' => $meta['label'],
                'feature' => $meta['feature'],
                'capabilities' => $perms
                    ->map(fn (string $p): array => ['key' => $p, 'label' => $labels[$p]['label'] ?? $p])
                    ->values(),
            ];
        }

        return response()->json(['data' => $data]);
    }

    /** GET /api/v1/roles — the org's custom roles. */
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        return response()->json([
            'data' => CustomRole::query()->orderBy('name')->get()->map(fn (CustomRole $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'permissions' => $r->permissions,
            ]),
        ]);
    }

    /** POST /api/v1/roles — create a custom role. */
    public function store(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $data = $this->validated($request);
        $role = CustomRole::create(['name' => $data['name'], 'permissions' => $data['permissions']]);

        return response()->json([
            'data' => ['id' => $role->id, 'name' => $role->name, 'permissions' => $role->permissions],
        ], 201);
    }

    /** PUT /api/v1/roles/{customRole} — update a custom role + re-sync members. */
    public function update(Request $request, CustomRole $customRole): JsonResponse
    {
        $this->ensureAdmin($request);

        $data = $this->validated($request, $customRole->id);
        $customRole->update(['name' => $data['name'], 'permissions' => $data['permissions']]);

        // Re-apply the (possibly changed) permissions to everyone holding this role.
        $this->membersOf($customRole)->each(fn (User $u) => $u->syncPermissions($customRole->permissions));

        return response()->json([
            'data' => ['id' => $customRole->id, 'name' => $customRole->name, 'permissions' => $customRole->permissions],
        ]);
    }

    /** DELETE /api/v1/roles/{customRole} — delete + downgrade members to Viewer. */
    public function destroy(CustomRole $customRole): JsonResponse
    {
        $this->ensureAdmin(request());

        /*
         * Strip the capabilities this role granted, and nothing else.
         *
         * This used to also `syncRoles(['acc_viewer'])` -- downgrade the member to the
         * accounting Viewer. That role does not exist in this deployment, so Spatie
         * threw RoleDoesNotExist and deleting any role that had a member answered 500.
         * Worse than the crash: syncRoles REPLACES a member's roles, so on a
         * deployment where it did exist it would have silently stripped an HR
         * officer's hr_officer along with the custom permissions.
         *
         * A custom role adds capabilities on top of whatever preset the member holds.
         * Deleting it removes the addition; it is not a demotion.
         */
        $this->membersOf($customRole)->each(function (User $u): void {
            $u->syncPermissions([]);
        });
        DB::table('org_user')
            ->where('org_id', CurrentOrg::id())
            ->where('custom_role_id', $customRole->id)
            ->update(['custom_role_id' => null]);

        $customRole->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** @return array{name: string, permissions: list<string>} */
    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('custom_roles', 'name')
                    ->where(fn ($q) => $q->where('org_id', CurrentOrg::id()))
                    ->ignore($ignoreId),
            ],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string'],
        ]);

        // The write-side half of the plan gate. `Rule::in` on the whole catalog
        // would accept anything the platform can do; what this org may GRANT is
        // narrower, and the difference is exactly what it paid for.
        $refused = CapabilityRegistry::refusedFor(CurrentOrg::id(), $data['permissions']);

        if ($refused !== []) {
            throw ValidationException::withMessages([
                'permissions' => 'Not included in this organisation\'s plan: '.implode(', ', $refused).'.',
            ]);
        }

        return $data;
    }

    /** Users in the current org assigned the given custom role. */
    private function membersOf(CustomRole $role)
    {
        $ids = DB::table('org_user')
            ->where('org_id', CurrentOrg::id())
            ->where('custom_role_id', $role->id)
            ->pluck('user_id');

        return User::whereIn('id', $ids)->get();
    }
}
