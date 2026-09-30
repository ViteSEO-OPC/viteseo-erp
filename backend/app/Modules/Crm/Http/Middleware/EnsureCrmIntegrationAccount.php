<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The last gate in front of inbound ingest: is this principal an integration identity at all?
 *
 * WHY THIS EXISTS AT ALL, when the route already carries `permission:crm.conversations.ingest`.
 * The CRM registers a global `Gate::before` that answers true for every ability a `super_admin`
 * is asked about (CrmServiceProvider). Laravel's permission middleware authorizes through
 * `can()`, so it consults that gate — which means a super_admin passes the ability check while
 * holding no such permission. For a human-facing route that is the intended product behaviour and
 * nothing here changes it. For a machine boundary it is a hole: the one credential class that must
 * never reach ingest is the most privileged one. This middleware closes it for this route only.
 *
 * HOW IT AVOIDS THE GATE. It never calls `can()`, `Gate::allows()` or `hasPermissionTo()` — every
 * one of those routes through the gate or through role inheritance. It reads the pivot tables
 * directly: `model_has_permissions` for a DIRECT grant, `model_has_roles` for any role at all.
 * A grant that arrived via a role is deliberately not enough; an integration identity is
 * provisioned with the ability attached to the account itself and nothing else.
 *
 * NOT BY EMAIL. Nothing here looks at the address. `meta-inbound-org7@crm.local` is an
 * administrative convention for auditability, and a convention is not an authorization fact: a
 * human could be created at that address, and a perfectly valid integration could be renamed.
 * The decision is made from account STATE — direct ability, zero roles, one real tenant.
 *
 * WHY NOT A SECOND SUSPENSION SYSTEM. Account-level and CRM-level suspension are already decided
 * upstream by `auth:sanctum` and `EnsureCrmAccessIsActive` on the group. This middleware answers
 * one question and adds no new lifecycle state.
 *
 * ONE ANSWER FOR EVERY FAILURE. Which condition failed is exactly the kind of detail that turns a
 * rejection into a probe — tell a caller "you have a role" and you have confirmed the account, the
 * ability and the shape of the check. Every path returns the same generic
 * `integration_account_required`; the operator diagnoses from the provisioning command, which
 * reports each condition in full.
 */
class EnsureCrmIntegrationAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->isIntegrationIdentity($user)) {
            return response()->json([
                'message' => 'This endpoint is restricted to provisioned integration accounts.',
                'code' => 'integration_account_required',
            ], 403);
        }

        return $next($request);
    }

    private function isIntegrationIdentity(User $user): bool
    {
        return $this->holdsAbilitiesDirectly($user)
            && $this->holdsNoRole($user)
            && $this->belongsToExactlyOneOrg($user);
    }

    /**
     * Every ability the provisioning command grants must be present as a DIRECT assignment.
     *
     * The list is read from the same config key `crm:integration-account` provisions from, so the
     * boundary and the provisioner cannot drift apart. `permissions()` is Spatie's direct pivot
     * relation, so this is a query against `model_has_permissions` and nothing else — no roles, no
     * gate, no cache of inherited abilities. `hasDirectPermission()` would be the expressive form
     * but it resolves the name to a model first and throws `PermissionDoesNotExist` when the
     * permission has not been seeded; a missing permission must mean "denied", not a 500.
     */
    private function holdsAbilitiesDirectly(User $user): bool
    {
        $required = (array) config('inbound.service_account.permissions', []);

        if ($required === []) {
            return false;
        }

        $granted = $user->permissions()->pluck('name')->all();

        foreach ($required as $ability) {
            if (! in_array($ability, $granted, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Any role at all disqualifies — not merely a privileged one.
     *
     * The relation carries no guard filter, so a role attached under any guard still counts, which
     * is the fail-closed direction. This is what stops a super_admin that someone also handed the
     * ingest ability directly, and equally a service account that has drifted into holding a human
     * role since it was provisioned.
     */
    private function holdsNoRole(User $user): bool
    {
        return ! $user->roles()->exists();
    }

    /**
     * Exactly one membership, and pointing at an org that exists.
     *
     * `org_user` carries a unique index on `user_id`, so the database already prevents the second
     * membership; what is actually reachable here is the ZERO case — an identity provisioned
     * without its tenant. The count is written as an equality rather than an existence check so
     * the guarantee does not silently depend on that index surviving a future migration.
     */
    private function belongsToExactlyOneOrg(User $user): bool
    {
        return DB::table('org_user')
            ->join('orgs', 'orgs.id', '=', 'org_user.org_id')
            ->where('org_user.user_id', $user->id)
            ->count() === 1;
    }
}
