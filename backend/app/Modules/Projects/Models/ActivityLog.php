<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Models\Concerns\BelongsToOrg;
use App\Models\User;
use App\Modules\Projects\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Always tied to a task; may also reference a subtask for subtask-level events.
 *
 * @mixin \Eloquent
 */
class ActivityLog extends Model
{
    use BelongsToOrg;
    use HasPublicUuid;

    public const UPDATED_AT = null;

    protected $table = 'pm_activity_logs';

    protected $fillable = [
        'task_id',
        'user_id',
        'action',
        'field_changed',
        'old_value',
        'new_value',
        'subtask_id',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function subtask(): BelongsTo
    {
        return $this->belongsTo(Subtask::class, 'subtask_id');
    }
}
