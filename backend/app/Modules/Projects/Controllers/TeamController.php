<?php

declare(strict_types=1);

namespace App\Modules\Projects\Controllers;

use App\Models\CustomRole;
use App\Http\Controllers\Controller;
use App\Models\Org;
use App\Models\User;
use App\Support\CurrentOrg;
use App\Support\Seats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Org-level team management for Sprintly (Projects): add / re-role / remove
 * the CURRENT org's project staff. Roles are the two Projects presets
 * (pm_apm = management tier — full projects access, pm_member = member tier —
 * read + contribute). Adding a member is capped by the org's seat limit
 * (see Seats).
 */
class TeamController extends Controller
{
    /** @var list<string> */
    private const PRESETS = ['pm_apm', 'pm_member'];

    private function ensureAdmin(Request $request): void
    {
        abort_unless(
            $request->user()->hasRole('pm_apm') || $request->user()->hasRole('super_admin'),
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

    private function projectsPreset(User $user): ?string
    {
        foreach (self::PRESETS as $role) {
            if ($user->hasRole($role)) {
                return $role;
            }
        }

        return null;
    }


    /**
     * A preset of THIS module, or one of the org's custom roles as "custom:<id>".
     *
     * Custom roles were refused here, and that broke the loop the feature exists for:
     * an admin could build "Front Desk" in Roles & Permissions and then had no way to
     * put anybody in it. The platform TeamController has always accepted them; these
     * three did not, so the half the operator actually uses was the half that could not.
     */
    private function roleIsAllowed(string $role): bool
    {
        if (in_array($role, self::PRESETS, true)) {
            return true;
        }

        if (! str_starts_with($role, 'custom:')) {
            return false;
        }

        // Org-scoped by the model's own global scope: another tenant's role id
        // simply does not exist here.
        return CustomRole::query()->whereKey((int) substr($role, 7))->exists();
    }

    /**
     * Apply a preset or a custom role to a member of the current org.
     *
     * Only THIS module's presets are stripped -- never syncRoles([...]), which would
     * also wipe the member's roles in the org's OTHER systems. A person can be an HR
     * employee and a CRM BD at once, and re-roling them in one must not silently
     * remove the other.
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

    /** GET /api/v1/projects/team — the current org's members + seat usage. */
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $orgId = CurrentOrg::id();
        $org = Org::find($orgId);
        $members = $org ? $org->users()->orderBy('name')->get() : collect();

        // A member holding one of this org's own roles is recorded on the pivot, not
        // in Spatie. Without reading it back the row would name whatever preset the
        // user no longer holds, and the screen could never show who is in a new role.
        $customByUser = DB::table('org_user')->where('org_id', $orgId)->pluck('custom_role_id', 'user_id');
        $customNames = CustomRole::query()->pluck('name', 'id');

        return response()->json([
            'data' => [
                'members' => $members->map(function (User $u) use ($customByUser, $customNames, $request): array {
                    $customId = $customByUser[$u->id] ?? null;
                    $hasCustom = $customId !== null && isset($customNames[$customId]);

                    return [
                        'id' => $u->id,
                        'name' => $u->name,
                        'email' => $u->email,
                        'role' => $hasCustom ? 'custom:' . $customId : $this->projectsPreset($u),
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

    /** POST /api/v1/projects/team — add a member to the current org (seat-capped). */
    public function store(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $orgId = CurrentOrg::id();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            // Optional, because a colleague who already has a sign-in keeps theirs.
            'password' => ['nullable', 'string', 'min:8'],
            'role' => ['required', 'string', function (string $attr, mixed $value, callable $fail): void {
                if (! $this->roleIsAllowed((string) $value)) {
                    $fail('That role is not available to this organisation.');
                }
            }],
        ]);

        // TWO SHAPES, chosen by whether the address already belongs to a colleague.
        //
        // One person holds ONE account for the whole platform, so the only way to put
        // an existing colleague into Sprintly was to find their row in the member list
        // and change it there — which is why the list had to show everyone in the
        // org, including the people with no access to this system at all. Giving the
        // address its own door is what lets the list be the people who use it.
        $colleague = User::query()->inCurrentOrg()->where('email', $data['email'])->first();

        // INVARIANT, and the reason the lookup is org-scoped: users are single-org
        // (Spatie roles are global, teams=false), so an address belonging to another
        // tenant must never be granted a role here. It is refused, not adopted.
        if ($colleague === null && User::query()->where('email', $data['email'])->exists()) {
            throw ValidationException::withMessages([
                'email' => 'That address already belongs to an account outside this organisation.',
            ]);
        }

        if ($colleague !== null) {
            // GRANT. No account is created, `name` and `password` are ignored rather
            // than applied — renaming a colleague is not what this button is for —
            // and no seat is spent, because they already occupy one.
            $this->applyRole($colleague, $data['role']);

            return response()->json([
                'data' => [
                    'id' => $colleague->id,
                    'name' => $colleague->name,
                    'email' => $colleague->email,
                    'role' => $data['role'],
                    'granted' => true,
                ],
            ]);
        }

        if (! Seats::canAdd($orgId)) {
            throw ValidationException::withMessages([
                'seat' => 'Seat limit reached ('.Seats::limitFor($orgId).' users). Ask the platform admin to add more seats.',
            ]);
        }

        if (($data['password'] ?? '') === '') {
            throw ValidationException::withMessages([
                'password' => 'A new account needs a temporary password.',
            ]);
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);
        Org::find($orgId)?->users()->attach($user->id, ['is_default' => true]);
        $this->applyRole($user, $data['role']);

        return response()->json([
            'data' => [
                'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
                'role' => $data['role'], 'granted' => false,
            ],
        ], 201);
    }

    /** PUT /api/v1/projects/team/{user} — change a member's role. */
    public function update(Request $request, User $user): JsonResponse
    {
        $this->ensureAdmin($request);
        $this->ensureMemberOfCurrentOrg($user);
        abort_if($user->id === $request->user()->id, 422, "You can't change your own role.");

        $data = $request->validate([
            'role' => ['required', 'string', function (string $attr, mixed $value, callable $fail): void {
                if (! $this->roleIsAllowed((string) $value)) {
                    $fail('That role is not available to this organisation.');
                }
            }],
        ]);

        // Strip only Projects' presets — syncRoles() would also wipe the
        // member's acc_*/inv_* roles on a multi-system org.
        foreach (self::PRESETS as $preset) {
            if ($user->hasRole($preset)) {
                $user->removeRole($preset);
            }
        }
        $this->applyRole($user, $data['role']);

        return response()->json(['data' => ['id' => $user->id, 'role' => $data['role']]]);
    }

    /** DELETE /api/v1/projects/team/{user} — remove a member from the current org. */
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
