<?php

namespace App\Modules\Crm\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Provider-neutral mailbox synchronization state. One row per synchronized mailbox.
 * `sync_cursor` holds whatever the active provider needs to resume: Gmail historyId,
 * Microsoft Graph deltaLink, or IMAP UIDVALIDITY:UID — the business layer never inspects it.
 *
 * @property Carbon|null $watch_expires_at Cast to datetime; declared so static analysis applies the cast.
 * @property Carbon|null $last_synced_at Cast to datetime; declared so static analysis applies the cast.
 */
#[Fillable([
    'email',
    'provider',
    'sync_cursor',
    'watch_expires_at',
    'last_synced_at',
    'status',
])]
class MailboxSync extends Model
{
    protected $table = 'crm_mailbox_syncs';

    protected function casts(): array
    {
        return [
            'watch_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }
}
