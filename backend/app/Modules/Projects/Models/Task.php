<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Models\User;
use App\Modules\Projects\Concerns\HasPublicUuid;
use App\Modules\Projects\Database\Factories\TaskFactory;
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
    /** @use HasFactory<TaskFactory> */
    use HasFactory, HasPublicUuid, SoftDeletes;

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
    ];

    protected $casts = [
        'assignment_type' => AssignmentType::class,
        'priority' => TaskPriority::class,
        'effort_score' => 'integer',
        'deadline' => 'date',
        'start_date' => 'date',
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
}
