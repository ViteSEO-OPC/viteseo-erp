<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A browser session lasts thirty days at most, however busy it is.
 *
 * The session lifetime (two days) is an IDLE timeout: every request pushes it
 * forward, so someone who works daily is never signed out by it. That is right
 * for convenience and wrong on its own -- a session copied off a laptop would then
 * live for as long as whoever copied it kept using it. Browser tokens had a hard
 * 30-day ceiling; this keeps it for the cookie that replaced them.
 *
 * It reads the session, not the user, so it does not matter which guard resolved
 * who you are or whether it runs before auth:sanctum. A request with no session --
 * a machine token -- passes straight through; its own expires_at bounds those.
 */
class EndSessionsPastTheirCeiling
{
    /** Session key AuthController::login() stamps; seconds since the epoch. */
    public const SIGNED_IN_AT = 'signed_in_at';

    public const CEILING_DAYS = 30;

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession()) {
            $signedInAt = $request->session()->get(self::SIGNED_IN_AT);

            if ($signedInAt !== null && $signedInAt < now()->subDays(self::CEILING_DAYS)->getTimestamp()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();

                throw new AuthenticationException('Your session has ended. Please sign in again.');
            }
        }

        return $next($request);
    }
}
