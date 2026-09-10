<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

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

        // A named token so it's identifiable; the plain text is shown once.
        $token = $user->createToken('web')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
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
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        return response()->json(['data' => ['message' => 'Password updated.']]);
    }

    /**
     * POST /api/v1/auth/logout
     *
     * Revoke just the token used for this request (other devices stay logged in).
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['data' => ['message' => 'Logged out.']]);
    }
}
