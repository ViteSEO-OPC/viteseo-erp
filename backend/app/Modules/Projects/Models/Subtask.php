<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Models\User;
use App\Modules\Projects\Concerns\HasApprovalWorkflow;
use App\Modules\Projects\Concerns\HasPublicUuid;
use App\Modules\Projects\Enums\ApprovalStatus;
use App\Modules\Projects\Enums\FlagReason;
use App\Modules\Projects\Enums\TaskPriority;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @mixin \Eloquent
 */
class Subtask extends Model
{
    use HasApprovalWorkflow, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_subtasks';

    protected $fillable = [
        'task_id',
        'name',
        'description',
        'assignee_id',
        'status_id',
        'priority',
        'effort_score',
        'deadline',
        'flag_reason',
        'flagged_at',
        // Approval workflow. NEVER accepted from a Form Request — these are set
        // only by SubtaskService / ApprovalService (see HasApprovalWorkflow).
        'approval_status',
        'submitted_by_id',
        'reviewed_by_id',
        'reviewed_at',
        'rejection_reason',
    ];

    protected $casts = [
        'priority' => TaskPriority::class,
        'effort_score' => 'integer',
        'deadline' => 'date',
        'flag_reason' => FlagReason::class,
        'flagged_at' => 'datetime',
        'approval_status' => ApprovalStatus::class,
        'reviewed_at' => 'datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(ProjectStatus::class, 'status_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'subtask_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(Link::class, 'subtask_id');
    }
}
