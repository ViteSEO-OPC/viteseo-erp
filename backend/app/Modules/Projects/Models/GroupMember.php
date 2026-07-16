<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Join row: group membership (members only — the leader is on pm_groups.leader_id).
 *
 * @mixin \Eloquent
 */
class GroupMember extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'pm_group_members';

    protected $fillable = [
        'group_id',
        'user_id',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'group_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
