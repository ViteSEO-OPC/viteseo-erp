<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Models\User;
use App\Modules\Projects\Concerns\HasPublicUuid;
use App\Modules\Projects\Database\Factories\ProjectFactory;
use App\Modules\Projects\Enums\ProjectState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @mixin \Eloquent
 */
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, HasPublicUuid, SoftDeletes;

    protected static function newFactory(): ProjectFactory
    {
        return ProjectFactory::new();
    }

    protected $table = 'pm_projects';

    protected $fillable = [
        'workspace_id',
        'name',
        'description',
        'priority',
        'color',
        'status',
    ];

    protected $casts = [
        'status' => ProjectState::class,
    ];

    /** Only projects that are still active (not marked done). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ProjectState::ACTIVE->value);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'workspace_id');
    }

    /** Users assigned to this project (team members). */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'pm_project_members', 'project_id', 'user_id')
            ->withPivot('created_at');
    }

    public function statuses(): HasMany
    {
        return $this->hasMany(ProjectStatus::class, 'project_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'project_id');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class, 'project_id');
    }
}
