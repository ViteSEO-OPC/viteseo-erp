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
use App\Support\AccessLifecycle;
use App\Support\CurrentOrg;
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
#[Fillable(['name', 'email', 'password', 'avatar', 'preferences', 'phone', 'status', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasRoles, HasFactory, Notifiable, SoftDeletes;

    /**
     * Closing, reopening or deleting the account changes everything they may use at
     * once, so their open sessions are judged again (AccessLifecycle).
     */
    protected static function booted(): void
    {
        static::updated(function (User $user): void {
            if ($user->wasChanged('status')) {
                AccessLifecycle::schedule($user, 'status');
            }
        });

        static::deleted(fn (User $user) => AccessLifecycle::schedule($user, 'deleted'));
    }

    /**
     * Platform-level account state — whether this person may sign in at all.
     *
     * NOT the CRM's notion of status. `Crm\Resources\UserResource` exposes a field
     * of the same name computed from `CrmAccessService::isSuspended()`, which is
     * CRM-scoped and lives in `crm_user_access`. This column is the whole account:
     * inactive here and no module is reachable, because the account cannot
     * authenticate. Keep the two apart when reading a payload.
     *
     * The column arrived with the CRM's additive users migration for exactly this
     * purpose ("reversible user deactivate") and was never wired to anything; CRM
     * solved its own case with a scoped table instead. Offboarding is the platform
     * case it was made for.
     */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /** May this account sign in and hold a session? */
    public function isActive(): bool
    {
        return $this->status !== self::STATUS_INACTIVE;
    }

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

        // An org's own admin may appoint a co-admin inside that org. Vite SEO
        // creates a client's FIRST admin; who else runs the place after that is
        // the client's business, not a support ticket. The org scope and the
        // one-org-per-user rule keep this inside their own tenant, and
        // super_admin stays absent from every list below.
        if ($this->hasRole('crm_admin')) {
            return ['crm_admin', 'crm_bd'];
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

    // --- Tenancy (multi-org membership) ------------------------------------

    /**
     * Orgs (tenants) this user belongs to. `is_default` marks the org resolved
     * on login until the user switches (org switcher, later slice).
     */
    public function orgs(): BelongsToMany
    {
        return $this->belongsToMany(Org::class, 'org_user')
            ->withPivot('is_default')
            ->withTimestamps();
    }

    /**
     * Restrict a user query to members of the CURRENT org.
     *
     * `users` is platform-wide on purpose (one account, many tenants), so it carries
     * no `org_id` and the BelongsToOrg global scope cannot fence it. Every directory,
     * picker and role listing that reads this table must apply this scope, or it
     * enumerates every other tenant's staff — names and email addresses included.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<User>  $query
     */
    public function scopeInCurrentOrg(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->whereIn('users.id', function ($sub): void {
            $sub->select('user_id')->from('org_user')->where('org_id', CurrentOrg::id());
        });
    }

    /** The org id resolved for this user — default org first, else any. */
    public function currentOrgId(): ?int
    {
        return $this->orgs()
            ->orderByDesc('org_user.is_default')
            ->orderBy('orgs.id')
            ->value('orgs.id');
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
