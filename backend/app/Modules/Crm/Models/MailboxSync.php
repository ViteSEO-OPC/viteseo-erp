<?php

namespace App\Modules\Crm\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Provider-neutral mailbox synchronization state. One row per synchronized mailbox.
 * `sync_cursor` holds whatever the active provider needs to resume: Gmail historyId,
 * Microsoft Graph deltaLink, or IMAP UIDVALIDITY:UID — the business layer never inspects it.
 * A null cursor means "never synced"; the provider bootstraps one on the next poll.
 *
 * `status`: active | paused | error. Only `paused` opts a mailbox out of the scheduled poll —
 * `error` is transient and keeps being retried (see SyncMailboxesCommand::targets()).
 *
 * @property Carbon|null $last_synced_at Cast to datetime; declared so static analysis applies the cast.
 */
#[Fillable([
    'email',
    'provider',
    'sync_cursor',
    'last_synced_at',
    'status',
])]
class MailboxSync extends Model
{
    use BelongsToOrg;
    protected $table = 'crm_mailbox_syncs';

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
        ];
    }
}
