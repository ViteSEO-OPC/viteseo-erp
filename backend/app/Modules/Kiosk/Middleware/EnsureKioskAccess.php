<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Middleware;

use App\Modules\Kiosk\Models\KioskAccessGrant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the 24h expiry on temp client logins. A `kiosk_client` may reach the
 * kiosk only while they hold an active (un-revoked, un-expired) grant; everyone
 * else (BD, super_admin) passes through. Runs after auth + set-current-org so the
 * grant lookup is org-scoped.
 */
class EnsureKioskAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->hasRole('kiosk_client')) {
            $active = KioskAccessGrant::query()
                ->where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->exists();

            abort_unless($active, 403, 'Your kiosk demo access has expired.');
        }

        return $next($request);
    }
}
