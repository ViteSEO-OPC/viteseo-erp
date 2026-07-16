<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Modules\Projects\Concerns\HasPublicUuid;
use App\Modules\Projects\Database\Factories\ProjectStatusFactory;
use App\Modules\Projects\Enums\StatusKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @mixin \Eloquent
 */
class ProjectStatus extends Model
{
    /** @use HasFactory<ProjectStatusFactory> */
    use HasFactory, HasPublicUuid;

    protected static function newFactory(): ProjectStatusFactory
    {
        return ProjectStatusFactory::new();
    }

    protected $table = 'pm_project_statuses';

    protected $fillable = [
        'project_id',
        'name',
        'key',
        'color',
        'is_protected',
        'order',
    ];

    protected $casts = [
        'key' => StatusKey::class,
        'is_protected' => 'boolean',
        'order' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'status_id');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(Subtask::class, 'status_id');
    }
}
