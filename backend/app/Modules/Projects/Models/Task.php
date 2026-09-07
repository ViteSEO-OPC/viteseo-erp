<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Models\Concerns\BelongsToOrg;
use App\Models\User;
use App\Modules\Projects\Concerns\HasApprovalWorkflow;
use App\Modules\Projects\Concerns\HasPublicUuid;
use App\Modules\Projects\Database\Factories\TaskFactory;
use App\Modules\Projects\Enums\ApprovalStatus;
use App\Modules\Projects\Enums\AssignmentType;
use App\Modules\Projects\Enums\TaskPriority;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @mixin \Eloquent
 */
class Task extends Model
{
    use BelongsToOrg;
    /** @use HasFactory<TaskFactory> */
    use HasApprovalWorkflow, HasFactory, HasPublicUuid, SoftDeletes;

    protected static function newFactory(): TaskFactory
    {
        return TaskFactory::new();
    }

    protected $table = 'pm_tasks';

    protected $fillable = [
        'project_id',
        'title',
        'description',
        'assignment_type',
        'group_id',
        'assignee_id',
        'assigned_by_id',
        'status_id',
        'priority',
        'effort_score',
        'deadline',
        'start_date',
        // Approval workflow. NEVER accepted from a Form Request — these are set
        // only by TaskService / ApprovalService (see HasApprovalWorkflow).
        'approval_status',
        'submitted_by_id',
        'reviewed_by_id',
        'reviewed_at',
        'rejection_reason',
    ];

    protected $casts = [
        'assignment_type' => AssignmentType::class,
        'priority' => TaskPriority::class,
        'effort_score' => 'integer',
        'deadline' => 'date',
        'start_date' => 'date',
        'approval_status' => ApprovalStatus::class,
        'reviewed_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'group_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(ProjectStatus::class, 'status_id');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(Subtask::class, 'task_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'task_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(Link::class, 'task_id');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class, 'task_id');
    }

    /**
     * An OPEN task: real work (approved) that nobody owns yet — no individual
     * assignee and no group.
     *
     * This is the claim pool's definition, kept on the model so the policy, the
     * pool query and the claim write can't drift apart on what "open" means.
     */
    public function isOpen(): bool
    {
        return ! $this->isProposal()
            && $this->assignee_id === null
            && $this->group_id === null;
    }

    /**
     * Whether $userId posted this open task and nobody has claimed it yet.
     *
     * An open post skips review precisely because it commits no one, which would
     * otherwise leave its author unable to fix a typo in it — they are neither
     * the assignee nor a proposal author. So they keep the same latitude a
     * proposal's author has, and lose it the moment someone claims the work.
     */
    public function isEditableOpenPostFor(?int $userId): bool
    {
        return $userId !== null
            && $this->submitted_by_id !== null
            && (int) $this->submitted_by_id === $userId
            && $this->isOpen();
    }
}
