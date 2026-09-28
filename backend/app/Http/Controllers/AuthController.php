<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EndSessionsPastTheirCeiling;
use App\Support\AccessLifecycle;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Core (shared) authentication controller.
 *
 * Not HR-specific — every module's users log in here. Issues Sanctum personal
 * access tokens; the frontend stores the token and sends it as a Bearer header
 * on every subsequent request.
 */
class AuthController extends Controller
{
    /**
     * POST /api/v1/auth/login
     *
     * Verify credentials, mint an API token, and return the user + roles so
     * the frontend can route to the correct portal.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        /*
         * validate() checks the email/password without logging anyone in.
         *
         * attempt() would do both in one call, and that is precisely the problem:
         * it fires the Login event, which writes "signed in" to the audit trail —
         * so a closed account refused on the next line would leave a permanent
         * record saying it had signed in. Credentials first, account state second,
         * and only then a login that is true.
         */
        /*
         * The browser, and only the browser.
         *
         * A first-party request -- Origin or Referer on the app's own domain -- is
         * given a session by Sanctum before it reaches here. Anything else is a
         * script, and all this endpoint could hand a script is a bearer token: a
         * 30-day credential that shows in no session list and ignores the idle
         * timeout. Machine clients get theirs from `crm:n8n-token` instead.
         *
         * Refused before the password is checked, so it is no password oracle either.
         */
        if (! $request->hasSession()) {
            abort(403, 'Sign in from the app.');
        }

        if (! Auth::validate($credentials)) {
            // Same generic error whether the email or the password was wrong —
            // don't reveal which, and keep the { message, errors } envelope.
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        /** @var User $user */
        $user = User::where('email', $credentials['email'])->firstOrFail();

        /*
         * Credentials were right; the account is closed.
         *
         * Deliberately NOT folded into the generic refusal above. That message
         * exists to hide WHICH half was wrong from someone guessing, and it earns
         * that cost. Here there is nothing to hide: the person proved they own the
         * account. Saying so is what stops a separated employee and the HR officer
         * helping them from spending days on password resets for an account that
         * will never open again.
         */
        if (! $user->isActive()) {
            abort(403, 'This account is no longer active. Contact HR.');
        }

        // Now it is a real sign-in: this is what fires Login, stamps last_login_at
        // and writes the audit row.
        Auth::login($user);

        // A new id for a new identity: a session id planted before sign-in is worth
        // nothing after it.
        $request->session()->regenerate();

        $request->session()->put([
            // What EndSessionsPastTheirCeiling counts thirty days from.
            EndSessionsPastTheirCeiling::SIGNED_IN_AT => now()->getTimestamp(),
            // Sanctum's AuthenticateSession ends a session whose stored hash no longer
            // matches the password -- but it stores that hash only on the session's
            // SECOND request, so a session that signed in and never came back would
            // survive a password change. Stored here, it cannot.
            'password_hash_web' => Auth::guard('web')->hashPasswordForCookie($user->getAuthPassword()),
        ]);

        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'roles' => $user->getRoleNames(),
                'employee' => $this->employeePayload($user),
            ],
        ]);
    }

    /**
     * GET /api/v1/auth/me
     *
     * Return the current user + roles + employee context (job title / department).
     * The frontend caches it for the greeting, portal routing, and the user card.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'roles' => $user->getRoleNames(),
                'employee' => $this->employeePayload($user),
            ],
        ]);
    }

    /**
     * Minimal employee context for the auth payload (null for users with no
     * employee record, e.g. a bare admin).
     *
     * @return array<string, mixed>|null
     */
    private function employeePayload($user): ?array
    {
        $employee = $user->employee;
        if (! $employee) {
            return null;
        }

        return [
            'id' => $employee->id,
            'job_title' => $employee->job_title,
            'department' => $employee->department?->name,
            // Management staff are exempt from attendance (self-service clock hidden).
            'is_management' => (bool) $employee->is_management,
        ];
    }

    /**
     * POST /api/v1/auth/change-password
     *
     * Verify the current password, set the new one, and revoke every OTHER
     * token so a stolen session can't outlive the change.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::min(8)],
        ]);

        $user = $request->user();
        $user->forceFill(['password' => \Illuminate\Support\Facades\Hash::make($data['password'])])->save();
        /*
         * Every other device signs in again; this one does not.
         *
         * Other browser sessions end on their next request, when AuthenticateSession
         * finds their stored hash no longer matches. This one survives because the
         * new hash is stored in it -- under a NEW id, so a copy of this very cookie,
         * which is what someone who stole it holds, dies with the old password.
         * Tokens are revoked outright, except the one making this request when a
         * machine client is the one making it.
         */
        $current = $user->currentAccessToken();

        if ($current instanceof PersonalAccessToken) {
            $user->tokens()->where('id', '!=', $current->id)->delete();
        } else {
            $user->tokens()->delete();
            $request->session()->regenerate();
            $request->session()->put('password_hash_web', Auth::guard('web')->hashPasswordForCookie($user->getAuthPassword()));
        }

        // Told now, so those browsers sign out at once rather than on their next click.
        app(AccessLifecycle::class)->sessionEnded($user, 'password-changed');

        return response()->json(['data' => ['message' => 'Password updated.']]);
    }

    /**
     * POST /api/v1/auth/logout
     *
     * End just the credential this request used -- other devices stay signed in.
     * A browser ends its session; a machine client (n8n) revokes its token.
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        } else {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['data' => ['message' => 'Logged out.']]);
    }
}
