<?php

namespace App\Modules\Crm\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A stored OAuth grant for one mailbox. One row per address; `email` is uniquely indexed.
 *
 * This model is the piece the 2026-07-08 migration assumed and never got: its docblock promised
 * "tokens are stored encrypted (model casts)" while no model existed to apply them. Until now the
 * table has been inert — no reader, no writer, zero rows — because Gmail sync authenticates through
 * service-account domain-wide delegation (see GoogleCredentialSource), which stores nothing.
 *
 * PERSISTENCE ONLY. Refresh scheduling, expiry decisions and revocation handling deliberately live
 * in the token-store service above this class, not here: whether a token is "expired" depends on a
 * refresh skew that is an operational choice, and a model that silently refreshed on read would make
 * a network call out of a property access.
 *
 * The `encrypted` casts mean ciphertext at rest and plaintext in PHP — so these attributes must
 * never be queried with a WHERE clause (each row has a distinct IV; equality matching cannot work).
 * Look rows up by `email`, which is exactly what the unique index is for.
 *
 * @property string $email the mailbox that granted consent
 * @property string $provider
 * @property string|null $access_token Encrypted at rest; plaintext when read.
 * @property string|null $refresh_token Encrypted at rest; plaintext when read.
 * @property Carbon|null $expires_at Access-token expiry. Cast to datetime; declared so static analysis applies the cast.
 */
#[Fillable([
    'email',
    'provider',
    'access_token',
    'refresh_token',
    'expires_at',
])]
class MailboxOauthToken extends Model
{
    /**
     * Explicit: Eloquent would otherwise derive `mailbox_oauth_tokens` and miss the module's
     * `crm_` prefix.
     */
    protected $table = 'crm_mailbox_oauth_tokens';

    /**
     * Keeps the credentials out of any array/JSON rendering of this model. No other CRM model sets
     * this because no other CRM model holds a secret — the framework default of serializing every
     * attribute is the wrong default here, and one accidental `toArray()` in a log line or an API
     * resource would be enough to leak a live refresh token.
     *
     * @var list<string>
     */
    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }
}
