<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Models\User;
use App\Modules\Projects\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Polymorphic via nullable FKs: a link belongs to a task XOR a subtask.
 *
 * @mixin \Eloquent
 */
class Link extends Model
{
    use HasPublicUuid;

    public const UPDATED_AT = null;

    protected $table = 'pm_links';

    protected $fillable = [
        'task_id',
        'subtask_id',
        'user_id',
        'url',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function subtask(): BelongsTo
    {
        return $this->belongsTo(Subtask::class, 'subtask_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
