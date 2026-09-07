<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Org;
use App\Models\User;
use App\Models\CustomRole;
use App\Support\CurrentOrg;
use App\Support\Seats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Org-level team management for the Finance Admin: add / re-role / remove staff
 * of the CURRENT org. A member's role is either a preset (acc_admin /
 * acc_accountant / acc_viewer) or one of the org's custom roles ("custom:<id>").
 */
class TeamController extends Controller
{
    private const PRESETS = ['acc_admin', 'acc_accountant', 'acc_viewer'];

    private function ensureAdmin(Request $request): void
    {
        abort_unless(
            $request->user()->hasRole('acc_admin') || $request->user()->hasRole('super_admin'),
            403,
        );
    }

    private function ensureMemberOfCurrentOrg(User $user): void
    {
        abort_unless(
            DB::table('org_user')->where('org_id', CurrentOrg::id())->where('user_id', $user->id)->exists(),
            404,
        );
    }

    private function isValidRole(string $role): bool
    {
        if (in_array($role, self::PRESETS, true)) {
            return true;
        }
        if (str_starts_with($role, 'custom:')) {
            return CustomRole::query()->whereKey((int) substr($role, 7))->exists();
        }

        return false;
    }

    /**
     * Apply a preset or custom role to a member of the current org.
     *
     * Only THIS module's presets are stripped before assigning — never
     * syncRoles([...]), which would also wipe the member's roles in the org's
     * OTHER systems (inv_*, booking_*) on a multi-system org.
     */
    private function applyRole(User $user, string $role): void
    {
        $orgId = CurrentOrg::id();

        foreach (self::PRESETS as $preset) {
            if ($user->hasRole($preset)) {
                $user->removeRole($preset);
            }
        }

        if (in_array($role, self::PRESETS, true)) {
            $user->assignRole($role);
            $user->syncPermissions([]);
            DB::table('org_user')->where('org_id', $orgId)->where('user_id', $user->id)
                ->update(['custom_role_id' => null]);

            return;
        }

        // firstOrFail: the role could be deleted between validation and here.
        $custom = CustomRole::query()->findOrFail((int) substr($role, 7));
        $user->syncPermissions($custom->permissions);
        DB::table('org_user')->where('org_id', $orgId)->where('user_id', $user->id)
            ->update(['custom_role_id' => $custom->id]);
    }

    private function accountingPreset(User $user): ?string
    {
        foreach (self::PRESETS as $role) {
            if ($user->hasRole($role)) {
                return $role;
            }
        }

        return null;
    }

    /** GET /api/v1/team — the current org's members. */
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $orgId = CurrentOrg::id();
        $org = Org::find($orgId);
        $members = $org ? $org->users()->orderBy('name')->get() : collect();

        $customByUser = DB::table('org_user')->where('org_id', $orgId)->pluck('custom_role_id', 'user_id');
        $customNames = CustomRole::query()->pluck('name', 'id');

        /*
         * `data: { members, seats }`, matching /hr/team, /crm/team and
         * /projects/team -- not the older `data: [...], seats: {...}` this
         * endpoint used to answer with.
         *
         * The shape moved because the SCREEN moved. Accounting used to have a
         * Team page of its own; it now renders the platform's shared
         * TeamAccountsManager, the same component the other three portals use,
         * and that component reads one envelope. The old shape was written when
         * Accounting was the only product and its team page the only caller.
         *
         * Note what is NOT filtered here: every member of the org is returned,
         * with `role: null` for anyone holding nothing in Accounting. The roster
         * is the non-null half and the "others in this organisation" count is
         * the null half -- so the screen can say who works in the books AND that
         * the rest exist, which a filtered list could not.
         */
        return response()->json([
            'data' => [
                'members' => $members->map(function (User $u) use ($customByUser, $customNames, $request): array {
                    $customId = $customByUser[$u->id] ?? null;
                    $hasCustom = $customId !== null && isset($customNames[$customId]);

                    return [
                        'id' => $u->id,
                        'name' => $u->name,
                        'email' => $u->email,
                        'role' => $hasCustom ? 'custom:' . $customId : $this->accountingPreset($u),
                        'role_label' => $hasCustom ? $customNames[$customId] : null,
                        'is_you' => $u->id === $request->user()->id,
                    ];
                })->values(),
                'seats' => [
                    'used' => Seats::used($orgId),
                    'limit' => Seats::limitFor($orgId),
                ],
            ],
        ]);
    }

    /** POST /api/v1/team — add a member to the current org. */
    public function store(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        // Seats are org-level (shared across systems), so the cap applies here too.
        if (! Seats::canAdd(CurrentOrg::id())) {
            throw ValidationException::withMessages([
                'seat' => 'Seat limit reached ('.Seats::limitFor(CurrentOrg::id()).' users). Ask the platform admin to add more seats.',
            ]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'string', fn ($attr, $value, $fail) => $this->isValidRole($value) ?: $fail('Invalid role.')],
        ]);

        // INVARIANT: users are single-org. Spatie roles/permissions are global
        // (config/permission.php teams=false), so a user in two orgs would carry
        // one org's role into the other. `unique:users,email` above enforces this
        // — team members are always freshly created, never an existing user. Do
        // NOT add an existing user to a second org until org-scoped roles (Spatie
        // teams) ship platform-wide.
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);
        Org::find(CurrentOrg::id())?->users()->attach($user->id, ['is_default' => true]);
        $this->applyRole($user, $data['role']);

        return response()->json([
            'data' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $data['role']],
        ], 201);
    }

    /** PUT /api/v1/team/{user} — change a member's role. */
    public function update(Request $request, User $user): JsonResponse
    {
        $this->ensureAdmin($request);
        $this->ensureMemberOfCurrentOrg($user);
        abort_if($user->id === $request->user()->id, 422, "You can't change your own role.");

        $data = $request->validate([
            'role' => ['required', 'string', fn ($attr, $value, $fail) => $this->isValidRole($value) ?: $fail('Invalid role.')],
        ]);
        $this->applyRole($user, $data['role']);

        return response()->json(['data' => ['id' => $user->id, 'role' => $data['role']]]);
    }

    /** DELETE /api/v1/team/{user} — remove a member from the current org. */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->ensureAdmin($request);
        $this->ensureMemberOfCurrentOrg($user);
        abort_if($user->id === $request->user()->id, 422, "You can't remove yourself.");

        $user->orgs()->detach(CurrentOrg::id());
        if ($user->orgs()->count() === 0) {
            $user->delete();
        }

        return response()->json(['data' => ['removed' => true]]);
    }
}
