<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\Concern;
use App\Modules\Hr\Models\ConcernRevealRequest;
use Illuminate\Validation\ValidationException;

/**
 * The identity-reveal negotiation on an anonymous concern.
 *
 * HR asks with a stated reason; the reporter accepts or declines with one of their own. Only an
 * acceptance stamps `hr_concerns.identity_revealed_at`, which is the single thing ConcernResource
 * consults before emitting a name.
 *
 * Every method here is written on the assumption that the reporter is the party with something
 * to lose. That is why the reason is mandatory on both sides, why a decline is final for that
 * request rather than something HR can appeal in place, and why nothing in the audit trail names
 * the reporter — including the lines about the request to name them.
 */
class ConcernRevealService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * HR asks. One open question at a time, and never about a concern whose reporter has
     * already agreed to be named.
     */
    public function request(Concern $concern, User $requester, string $reason): ConcernRevealRequest
    {
        if (! $concern->is_anonymous) {
            throw ValidationException::withMessages([
                'concern_id' => ['This concern was not filed anonymously — the reporter is already named.'],
            ]);
        }

        if ($concern->identity_revealed_at !== null) {
            throw ValidationException::withMessages([
                'concern_id' => ['The reporter has already agreed to be named on this concern.'],
            ]);
        }

        if ($concern->revealRequests()->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages([
                'concern_id' => ['You already have a reveal request waiting on this concern.'],
            ]);
        }

        $request = ConcernRevealRequest::create([
            'concern_id' => $concern->id,
            'requested_by' => $requester->id,
            'reason' => $reason,
            'status' => 'pending',
        ])->load('requester');

        // ANONYMITY: the reference number only. An audit line saying who was asked to identify
        // themselves would leak the identity the request has not been granted — the exact
        // failure this feature exists to prevent, committed by the feature itself.
        $this->audit->log(
            'requested',
            'Concerns',
            "Asked the reporter of {$concern->reference_no} to identify themselves",
            $concern,
        );

        return $request;
    }

    /**
     * The reporter agrees. This is the only path that makes an identity visible.
     *
     * Stamped on the CONCERN rather than inferred from this row, so a reader that forgot to
     * load the request history cannot accidentally decide the question either way.
     */
    public function accept(ConcernRevealRequest $request, User $actor): ConcernRevealRequest
    {
        $concern = $this->assertOwnPending($request, $actor, 'accept');

        $request->update([
            'status' => 'accepted',
            'responded_at' => now(),
        ]);

        $concern->update(['identity_revealed_at' => now()]);

        $this->audit->log(
            'approved',
            'Concerns',
            "The reporter of {$concern->reference_no} agreed to be identified to HR",
            $concern,
        );

        return $request->load('requester');
    }

    /** The reporter says no, and says why. The concern stays anonymous. */
    public function decline(ConcernRevealRequest $request, User $actor, string $reason): ConcernRevealRequest
    {
        $concern = $this->assertOwnPending($request, $actor, 'decline');

        $request->update([
            'status' => 'declined',
            'responded_at' => now(),
            'decline_reason' => $reason,
        ]);

        // The reason is deliberately NOT in the audit line. It is the reporter's own words about
        // why they will not be named, and those words can identify them as surely as the name
        // would — HR reads it on the concern, where it belongs, and not in a log others browse.
        $this->audit->log(
            'declined',
            'Concerns',
            "The reporter of {$concern->reference_no} declined to be identified",
            $concern,
        );

        return $request->load('requester');
    }

    /**
     * Guard shared by both answers: the responder must be the person who filed the concern, and
     * the question must still be open.
     *
     * Ownership is checked against `$concern->employee_id`, which is stored on every concern
     * including anonymous ones — this is the one place in the module that reads it for a
     * decision rather than for display, and it never leaves the service.
     */
    private function assertOwnPending(ConcernRevealRequest $request, User $actor, string $action): Concern
    {
        $concern = $request->concern;

        if ($concern === null) {
            throw ValidationException::withMessages([
                'status' => ['The concern this request belongs to no longer exists.'],
            ]);
        }

        $employeeId = $actor->employee?->id;

        if ($employeeId === null || (int) $employeeId !== (int) $concern->employee_id) {
            throw ValidationException::withMessages([
                'status' => ["Only the person who raised this concern can {$action} the request."],
            ]);
        }

        if ($request->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => ["This request has already been {$request->status}."],
            ]);
        }

        return $concern;
    }
}
