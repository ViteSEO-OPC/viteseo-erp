<?php

declare(strict_types=1);

namespace App\Modules\Crm\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * Records when an account last signed in.
 *
 * `users.last_login_at` was added by the CRM's additive users migration, whose docblock states it is
 * "stamped on successful login (CRM 'Last Login' column)" — and nothing ever stamped it. The column
 * has been null for every account since it shipped, so the Team Accounts "Last Active" column and the
 * Credentials modal's "Last sign-in" row have only ever rendered an em dash. This closes that gap;
 * both surfaces already read the field and need no change.
 *
 * WHY A LISTENER AND NOT `AuthController::login`. The one-line write belongs there on the merits — a
 * sign-in timestamp is an account fact, not a CRM decision — but `AuthController` is the SHARED
 * authentication flow behind TeamOra, Zerem and Sprintly, and this module does not edit it.
 * `Illuminate\Auth\Events\Login` is the seam Laravel provides: `SessionGuard::fireLoginEvent()`
 * dispatches it on every successful `Auth::attempt()`, and CrmServiceProvider already wires listeners
 * this way. Nothing about the login flow changes; this only observes it.
 *
 * It therefore stamps for EVERY product's logins, not just Zerem's — which is correct. "When did this
 * account last sign in" is true platform-wide; a Zerem-only reading would be a different, narrower
 * fact and is not what the column claims. Contrast `users.status`, a CRM access decision that had no
 * business on the shared row and now lives in `crm_user_access`.
 *
 * Deliberately NOT an Activity Log entry: this is a column the UI reads, not an audit event, and the
 * append-only trail is not the place for a per-login row.
 */
class StampLastLoginAt
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        // Only the shared platform account carries this column. Guarded rather than assumed so a
        // future guard authenticating some other Authenticatable cannot fatal the login it observes.
        if (! $user instanceof User) {
            return;
        }

        $user->last_login_at = now();

        // `timestamps = false` so `updated_at` does NOT move. Signing in is not an edit to the
        // account, and letting every login bump it would make "when was this account last changed"
        // useless — it would answer "when did they last log in" for every active user. It also keeps
        // the whole-row `users` assertions in the suspension and access tests meaningful: those prove
        // a CRM action writes nothing to the shared row, and a drifting `updated_at` would blunt them.
        $user->timestamps = false;

        // saveQuietly(): no model events, so no observer, no Activity row, and nothing else reacts to
        // a login. Combined with the above, `last_login_at` is the only column this touches.
        $user->saveQuietly();
    }
}
