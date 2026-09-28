<?php

namespace App\Support;

use App\Models\User;

/**
 * Tokens for machines -- the Zerem scraper, n8n -- which cannot sign in.
 *
 * People sign in with a session cookie, so a token is now only ever a service's
 * credential, kept in that service's own config. Each is issued with its expiry
 * written into it: long enough that the service is not silently cut off every
 * month, short enough that a leaked one does not live forever.
 */
final class MachineTokens
{
    /** How long a machine token lives unless the caller says otherwise. */
    public const DEFAULT_DAYS = 90;

    /** How long any token minted without an expiry lives (see AppServiceProvider). */
    public const UNSTATED_DAYS = 30;

    /** Issue a token and return its plain text -- shown once, never stored. */
    public static function issue(User $user, string $name, int $days = self::DEFAULT_DAYS): string
    {
        return $user->createToken($name, ['*'], now()->addDays($days))->plainTextToken;
    }

    /** Revoke every token of that name the account holds; returns how many. */
    public static function revoke(User $user, string $name): int
    {
        return $user->tokens()->where('name', $name)->delete();
    }
}
