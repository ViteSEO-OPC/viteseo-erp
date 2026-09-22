<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse every request made by an account that has been closed.
 *
 * Refusing the login door is not enough on its own. Sanctum tokens live 30 days,
 * so someone separated at 4pm still holds this morning's token in an open tab and
 * would keep working until it expired. This runs on every API request, which is
 * also why nothing here revokes tokens: a token belonging to a closed account is
 * already inert, and leaving it in place means a reinstatement puts the person
 * back exactly where they were, on the session they never lost.
 *
 * That is the same bargain {@see \App\Modules\Crm\Services\CrmAccessService} makes
 * for its own scoped suspension: never remove, only refuse, so restoring is one
 * write and nothing has to be remembered.
 *
 * A guest request passes straight through — this middleware says nothing about
 * who may authenticate, only about who may still act once they have.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->isActive()) {
            abort(403, 'This account is no longer active. Contact HR.');
        }

        return $next($request);
    }
}
