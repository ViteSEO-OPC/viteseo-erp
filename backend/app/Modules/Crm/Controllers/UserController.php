<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;

use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Notifications\LeadsReassignedNotification;
use App\Modules\Crm\Requests\ReassignLeadsRequest;
use App\Modules\Crm\Requests\StoreUserRequest;
use App\Modules\Crm\Requests\UpdateUserRequest;
use App\Modules\Crm\Resources\BdPerformanceSnapshotResource;
use App\Modules\Crm\Resources\UserLookupResource;
use App\Modules\Crm\Resources\UserResource;
use App\Modules\Crm\Models\BdPerformanceSnapshot;
use App\Modules\Crm\Services\CrmAccessService;
use App\Modules\Crm\Services\LeadAssignmentService;
use App\Modules\Crm\Services\Planner\PlannerOwnershipTransfer;
use App\Modules\Crm\Services\Performance\BdPerformanceSnapshotService;
use App\Models\Org;
use App\Models\User;
use App\Support\CurrentOrg;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $actor = $request->user();

        $users = User::query()
            // `users` is platform-wide and carries no org_id, so the global scope cannot
            // fence it: Team Accounts listed every tenant's accounts, emails included.
            ->inCurrentOrg()
            ->with('roles')
            // Portfolio size, for the Team Accounts "Reassign all leads (N)" action. A correlated
            // subquery rather than withCount(), so the shared User model needs no CRM relation.
            ->addSelect(['lead_count' => Lead::query()
                ->selectRaw('count(*)')
                ->whereColumn('crm_leads.assigned_to', 'users.id')
                ->getQuery(), ])
            // Role-scoped listing: CRM Admin sees BD accounts only; Super Admin sees everyone.
            // (Extension point: future assigned-user scoping plugs in here.)
            ->when(! $actor->hasRole('super_admin'), function (Builder $query): void {
                $query->whereHas('roles', fn (Builder $q) => $q->where('name', 'crm_bd'));
            })
            ->latest('id')
            ->paginate(15);

        return UserResource::collection($users);
    }

    /**
     * GET /users/lookup?email=… — does this address already belong to a centralized user?
     *
     * The entry point for the email-first Create Account flow. Deliberately NOT part of `index()`:
     * it searches every platform account rather than the BD-scoped list, so it returns a minimal,
     * purpose-built payload ({@see UserLookupResource}) instead of the full UserResource — an admin
     * discovering a colleague to grant access to has no need for their permission set or phone
     * number. Exact-match only, so it cannot be walked as a directory.
     *
     * Gated by the same `viewAny` policy as the list.
     */
    public function lookup(Request $request, CrmAccessService $access): JsonResponse
    {
        $email = trim($request->string('email')->toString());

        /*
         * Scoped to THIS org, and the feature still works.
         *
         * The grant path exists for one person holding one account across several
         * modules -- an HRIS colleague being given CRM access -- and that colleague is
         * by definition in the same org. Searching the whole platform therefore bought
         * nothing the feature needed, while telling any CRM admin whether a given
         * email address exists in some OTHER tenant, along with its owner's name and
         * roles. One org per user (2026_09_03_090000) makes the wider search useless
         * as well as unsafe: a user found in another org could not be added here.
         */
        $user = $email === ''
            ? null
            : User::query()->inCurrentOrg()->with('roles')->where('email', $email)->first();

        return response()->json([
            'data' => $user ? new UserLookupResource($user, $access) : null,
        ]);
    }

    public function store(StoreUserRequest $request, CrmAccessService $access): JsonResponse
    {
        $validated = $request->validated();

        // GRANT PATH — the email already belongs to a centralized user (an HRIS/Projects colleague).
        // They get the CRM role added to the account they already have: no second User row, no
        // password, no write to the users table at all. StoreUserRequest has already rejected any
        // attempt to send name/status/password alongside it.
        if ($existing = $request->existingUser()) {
            // Escalation guard for the grant target (Super Admin bypasses via Gate::before).
            $this->authorize('grantCrmAccess', $existing);

            $access->grant($existing, $validated['role']);

            return response()->json([
                'data' => new UserResource($existing->load('roles')),
                // Tells the client which of the two shapes it got back. There is no
                // `temporary_password` here — that is the point of this branch.
                'granted' => true,
            ]);
        }

        // CREATE PATH — unchanged. Server generates the temporary password unless the admin supplied
        // one. Returned once (below) so it can be copied and handed off — never stored in plaintext.
        $temporaryPassword = $validated['password'] ?? Str::password(16);

        // `status` is deliberately absent: it belongs to the shared `users` row, and the CRM no
        // longer writes that column anywhere. The database default (`active`) applies, and CRM
        // access state — the thing this module actually owns — lives in `crm_user_access`.
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $temporaryPassword, // hashed via the model cast
        ]);

        $user->assignRole($validated['role']);

        // Membership, not the role, is what makes this person part of the org. Without
        // it the account cannot sign in at all: the login audit row carries org_id NOT
        // NULL and resolves it from membership, so the first attempt died on a
        // constraint violation. A BD created here was locked out of Zerem the moment
        // they were handed their password.
        if (($orgId = CurrentOrg::id()) !== null) {
            Org::find($orgId)?->users()->attach($user->id, ['is_default' => true]);
        }

        return response()->json([
            'data' => new UserResource($user->load('roles')),
            'temporary_password' => $temporaryPassword,
            'granted' => false,
        ], 201);
    }

    /**
     * Edit a CRM team account: rename, change CRM role, or suspend/restore Zerem access.
     *
     * `status` NO LONGER writes `users.status`. That column lives on the row shared by TeamOra
     * (HRIS), Zerem (CRM) and Sprintly (Projects), so suspending a BD through it made a CRM-only
     * decision with a platform-wide blast radius — and, since nothing ever read it at sign-in, it
     * blocked precisely nothing. CRM suspension now records to the module-owned `crm_user_access`
     * table via {@see CrmAccessService} and is enforced per-request by
     * {@see \App\Modules\Crm\Http\Middleware\EnsureCrmAccessIsActive}.
     *
     * `$user->save()` is reached ONLY by a rename. A status-only PATCH must leave the shared row
     * byte-identical, which is what makes a CRM suspension invisible to the other two products.
     */
    public function update(
        UpdateUserRequest $request,
        User $user,
        CrmAccessService $access,
        BdPerformanceSnapshotService $snapshots,
    ): JsonResponse
    {
        $validated = $request->validated();

        if (array_key_exists('name', $validated)) {
            $user->name = $validated['name'];
            $user->save();
        }

        if (array_key_exists('status', $validated)) {
            $this->applyCrmAccess($request, $user, $access, $snapshots, $validated['status']);
        }

        if (array_key_exists('role', $validated)) {
            // CRM-SCOPED, never `syncRoles([$role])`. Syncing the user's entire role set silently
            // deleted `hr_employee` / `pm_member` from any multi-module colleague edited here —
            // cutting them out of TeamOra and Sprintly as a side effect of a CRM rename. The service
            // may only detach the OTHER CRM role.
            $access->setCrmRole($user, $validated['role']);
        }

        return response()->json([
            'data' => new UserResource($user->load('roles')),
        ]);
    }

    /**
     * Suspend or restore Zerem access, with the two guard rails {@see self::destroy()} already had
     * and this path did not.
     *
     * Suspension is access control ONLY: it never writes a role, a lead, a Planner event, a score or
     * any attribution column, and it never revokes the Sanctum token — one token serves all three
     * products, so revoking it here would sign the user out of TeamOra and Sprintly. Handing a
     * resigned BD's workload to someone else is a separate, explicit Admin operation.
     */
    /**
     * POST /users/{user}/reassign-leads — hand a departing BD's whole portfolio to a replacement.
     *
     * The resignation counterpart to the single-lead assign, and deliberately built ON it: every lead
     * goes through {@see LeadAssignmentService}, so each one writes its own `lead.assigned` Activity
     * and runs the Planner transfer. There is no bulk `UPDATE` here — a faster query that produced no
     * audit trail is the exact failure mode {@see PlannerOwnershipTransfer} exists to prevent.
     *
     * WHAT MOVES: current lead ownership, each lead's open Planner work, and — once, at the end — the
     * departing BD's STANDALONE open work, which is keyed to no lead and would otherwise be stranded
     * with an account that can no longer sign in.
     *
     * WHAT STAYS WITH THE DEPARTING BD, permanently: completed Planner events, calls, notes, emails,
     * every Activity they caused, `qualified_by`, `created_by`, and their performance history. None of
     * those are in any write path below.
     *
     * A snapshot is captured FIRST when this drains the portfolio, because the moment the leads move,
     * the custody-based metrics move with them and their record is unrecoverable.
     */
    public function reassignLeads(
        ReassignLeadsRequest $request,
        User $user,
        LeadAssignmentService $assignment,
        PlannerOwnershipTransfer $plannerOwnership,
        BdPerformanceSnapshotService $snapshots,
    ): JsonResponse {
        $this->authorize('update', $user);

        $actor = $request->user();
        $toUserId = (int) $request->validated('to_user_id');

        if ($toUserId === $user->id) {
            abort(422, 'Select a different Business Development Manager.');
        }

        $leads = Lead::query()->where('assigned_to', $user->id)->get();

        // Freeze the record BEFORE ownership moves. Every custody metric (M-01…M-05, C1) reads
        // `crm_leads.assigned_to`, so once these leads are gone the departing BD's performance is not
        // merely zero — it is unrecoverable. Skipped when there is nothing to drain.
        if ($leads->isNotEmpty()) {
            $snapshots->capture($user, BdPerformanceSnapshot::REASON_REASSIGNMENT);
        }

        $moved = 0;
        foreach ($leads as $lead) {
            if ($assignment->assign($lead, $toUserId, $actor)) {
                $moved++;
            }
        }

        // Once, after the leads: work that belongs to the person rather than to any lead.
        $standalone = $plannerOwnership->transferStandaloneWork($user->id, $toUserId);

        // ONE bell for the whole handover — the per-lead Activity records remain the granular audit.
        $recipient = User::find($toUserId);
        if ($recipient !== null && ($moved > 0 || $standalone > 0)) {
            $recipient->notify(new LeadsReassignedNotification($user, $actor, $moved, $standalone));
        }

        return response()->json([
            'data' => [
                'leads_reassigned' => $moved,
                'standalone_events_transferred' => $standalone,
            ],
        ]);
    }

    /**
     * GET /users/{user}/performance-snapshots — this BD's frozen performance readings, newest first.
     *
     * The answer to "what was Jane's record before her leads moved?", which is otherwise unanswerable:
     * every live metric is derived from current ownership, so once a portfolio is handed on there is
     * nothing left to query. Gated by the same `view` policy as the account itself.
     */
    public function performanceSnapshots(Request $request, User $user): AnonymousResourceCollection
    {
        $this->authorize('view', $user);

        return BdPerformanceSnapshotResource::collection(
            BdPerformanceSnapshot::query()
                ->where('user_id', $user->id)
                ->orderByDesc('captured_at')
                ->orderByDesc('id')
                ->paginate(15)
        );
    }

    private function applyCrmAccess(
        Request $request,
        User $user,
        CrmAccessService $access,
        BdPerformanceSnapshotService $snapshots,
        string $status,
    ): void
    {
        if ($status !== 'inactive') {
            $access->restore($user);

            return;
        }

        // Locking yourself out of the tool you administer is never the intent, and with enforcement
        // live it is not self-correcting: the Team Accounts page that would undo it is behind the
        // very check being tripped.
        if ($request->user()->id === $user->id) {
            abort(422, 'You cannot suspend your own account.');
        }

        // Mirrors destroy(): an owner account is not suspendable through CRM Team Accounts.
        if ($user->hasRole('super_admin')) {
            abort(422, 'Super Admin accounts cannot be suspended.');
        }

        // ORDER IS LOAD-BEARING. BdRankingService builds its cohort from BDs whose access is live, so
        // the snapshot must be taken while this BD is still IN that cohort. Capturing after the
        // suspension row lands would silently record an empty ranking for the very person being
        // captured. One transaction so a failed capture can never leave a suspension without the
        // record it was supposed to preserve.
        DB::transaction(function () use ($request, $user, $access, $snapshots): void {
            $snapshots->capture($user, BdPerformanceSnapshot::REASON_SUSPENSION);
            $access->suspend($user, $request->user());
        });
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        // Guard rails on top of the Super-Admin-only policy: never delete yourself or an owner account.
        if ($request->user()->id === $user->id) {
            abort(422, 'You cannot delete your own account.');
        }

        if ($user->hasRole('super_admin')) {
            abort(422, 'Super Admin accounts cannot be deleted.');
        }

        $user->delete();

        return response()->json(null, 204);
    }
}
