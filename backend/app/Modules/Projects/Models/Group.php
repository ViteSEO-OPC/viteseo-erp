<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Models\Concerns\BelongsToOrg;
use App\Models\User;
use App\Modules\Projects\Concerns\HasPublicUuid;
use App\Modules\Projects\Database\Factories\GroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @mixin \Eloquent
 */
class Group extends Model
{
    use BelongsToOrg;
    /** @use HasFactory<GroupFactory> */
    use HasFactory, HasPublicUuid;

    protected static function newFactory(): GroupFactory
    {
        return GroupFactory::new();
    }

    protected $table = 'pm_groups';

    protected $fillable = [
        'name',
        'color',
        'leader_id',
        'archived',
    ];

    protected $casts = [
        'archived' => 'boolean',
    ];

    /** Projects this group belongs to (a group can span many projects). */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'pm_project_group', 'group_id', 'project_id')
            ->withPivot('created_at');
    }

    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leader_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'pm_group_members', 'group_id', 'user_id')
            ->withPivot('created_at');
    }

    public function groupMembers(): HasMany
    {
        return $this->hasMany(GroupMember::class, 'group_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'group_id');
    }
}
