<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * Public forgot/reset-password flow (core, shared by every module's users).
 *
 * The reset link lands on the frontend (`FRONTEND_URL/reset-password`) — see
 * AppServiceProvider::boot for the URL builder — and the frontend posts the
 * token back here.
 */
class PasswordResetController extends Controller
{
    /** POST /api/v1/auth/forgot-password */
    public function sendLink(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink($request->only('email'));

        // Identical response whether or not the account exists — the endpoint
        // must not double as an email-enumeration oracle.
        return response()->json([
            'data' => ['message' => "If that email has an account, we've sent a reset link."],
        ]);
    }

    /** POST /api/v1/auth/reset-password */
    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset(
            $data,
            static function (User $user, string $password): void {
                $user->forceFill(['password' => Hash::make($password)])->save();
                // A reset invalidates every existing session/token.
                $user->tokens()->delete();
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        return response()->json(['data' => ['message' => 'Password has been reset. You can now log in.']]);
    }
}
