<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Modules\Hr\Models\Employee;
use App\Modules\Projects\Models\ActivityLog;
use App\Modules\Projects\Models\Comment;
use App\Modules\Projects\Models\Group;
use App\Modules\Projects\Models\Link;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Subtask;
use App\Modules\Projects\Models\Task;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

// `avatar`, `preferences` are Projects-module columns added by the additive
// users migration (`capacity_points` was dropped — workload is now derived from
// live effort aggregation); `status`, `last_login_at` + soft-deletes are
// added by the CRM module's additive users migration (CRM needs reversible user
// "deactivate"/"delete"). `role` is intentionally absent (Spatie roles are the
// single source of truth).
#[Fillable(['name', 'email', 'password', 'avatar', 'preferences', 'status', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasRoles, HasFactory, Notifiable, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'preferences' => 'array',
        ];
    }

    /**
     * Roles this user may assign when creating/editing CRM team accounts.
     * super_admin → crm_admin + crm_bd; crm_admin → crm_bd only; anyone else → none.
     * (Aligned to platform per-system Spatie roles; super_admin itself is never assignable.)
     *
     * @return list<string>
     */
    public function assignableRoles(): array
    {
        if ($this->hasRole('super_admin')) {
            return ['crm_admin', 'crm_bd'];
        }

        if ($this->hasRole('crm_admin')) {
            return ['crm_bd'];
        }

        return [];
    }

    /**
     * The HR employee record for this user (1-to-1). Null for users without one
     * (e.g. a bare admin). "My X" endpoints resolve identity via $user->employee.
     */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    // --- Projects (PMS) module relationships -------------------------------

    /**
     * Projects this user is a member of. Team membership lives at the project
     * level (pm_project_members); a workspace is only a grouping of projects.
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'pm_project_members', 'user_id', 'project_id')
            ->withPivot('created_at');
    }

    /**
     * Groups this user is a member of (leadership is separate — see ledGroups()).
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'pm_group_members', 'user_id', 'group_id')
            ->withPivot('created_at');
    }

    /** Groups this user leads. */
    public function ledGroups(): HasMany
    {
        return $this->hasMany(Group::class, 'leader_id');
    }

    /** Tasks assigned individually to this user. */
    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assignee_id');
    }

    public function assignedSubtasks(): HasMany
    {
        return $this->hasMany(Subtask::class, 'assignee_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'author_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(Link::class, 'user_id');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class, 'user_id');
    }
}
