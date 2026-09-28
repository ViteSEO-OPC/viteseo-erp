<?php

namespace App\Support;

use App\Events\AccessChanged;
use App\Models\User;
use App\Modules\Crm\Services\CrmAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Illuminate\Support\defer;

/**
 * What happens to someone's open sessions when what they may use changes.
 *
 * Every place access changes reaches here -- a role or permission granted or taken
 * (Spatie's events, AppServiceProvider), a CRM suspension, the account closing or
 * being deleted (User::booted) -- so no controller has to remember to.
 *
 * Two outcomes. If they still have a system to use, their open browsers are told,
 * re-read who they are, and are walked out of anything they lost. If nothing is
 * left, every session and token they hold is ended on the server first, so the
 * broadcast that follows is a courtesy, not the safeguard.
 */
final class AccessLifecycle
{
    /** Permission namespaces that open a system -- the ones the launcher reads (core/access.ts). */
    private const SYSTEMS = ['hr.', 'crm.', 'projects.', 'accounting.', 'kiosk.'];

    /**
     * Judge once the current request, command or job has finished.
     *
     * Not on the spot: a role change is usually a removal followed by an assignment
     * -- every TeamController swaps a module's presets that way -- and judged between
     * the two, a person whose only role was being swapped would look as if they had
     * nothing left, and be signed out for being promoted. Named per person, so a burst
     * of changes to one account is judged once, on how it finally stands. `always`,
     * because the change may have landed even if the request then failed.
     */
    public static function schedule(User $user, string $reason): void
    {
        $userId = $user->id;

        defer(fn () => app(self::class)->judge($userId, $reason), 'access-changed:'.$userId, always: true);
    }

    /** Decide, act on the server, then tell their browsers. */
    public function judge(int $userId, string $reason): void
    {
        $user = User::withTrashed()->find($userId);
        $signedOut = $user === null || ! $this->hasAnythingLeft($user);

        if ($signedOut) {
            $this->endEverywhere($userId);
        }

        AccessChanged::dispatch($userId, $signedOut, $reason);
    }

    /** One of their sessions was ended from another device: tell the rest now. */
    public function sessionEnded(User $user, string $reason): void
    {
        AccessChanged::dispatch($user->id, false, $reason);
    }

    /** Does anything they hold still open a system? The launcher's rule, minus org entitlements. */
    public function hasAnythingLeft(User $user): bool
    {
        if ($user->trashed() || ! $user->isActive()) {
            return false;
        }

        // HRIS self-service rides on the role itself, not only on its permissions.
        if ($user->hasRole('hr_employee')) {
            return true;
        }

        $suspendedFromCrm = app(CrmAccessService::class)->isSuspended($user);

        return $user->getAllPermissions()
            ->pluck('name')
            ->reject(fn (string $name): bool => $suspendedFromCrm && str_starts_with($name, 'crm.'))
            ->contains(fn (string $name): bool => Str::startsWith($name, self::SYSTEMS));
    }

    /**
     * End every session and token the account holds.
     *
     * Only database sessions can be found by their owner, and that is the driver
     * production runs (SESSION_DRIVER=database). This runs after the request that
     * caused it has written its own session, so deleting that row too is final.
     */
    private function endEverywhere(int $userId): void
    {
        if (config('session.driver') === 'database') {
            DB::table((string) config('session.table', 'sessions'))->where('user_id', $userId)->delete();
        }

        DB::table('personal_access_tokens')
            ->where('tokenable_type', (new User)->getMorphClass())
            ->where('tokenable_id', $userId)
            ->delete();
    }
}
