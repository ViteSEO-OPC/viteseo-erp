<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Models\Concerns\BelongsToOrg;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Join row: which users are assigned to a project. Role stays global on users.
 *
 * @mixin \Eloquent
 */
class ProjectMember extends Model
{
    use BelongsToOrg;
    public const UPDATED_AT = null;

    protected $table = 'pm_project_members';

    protected $fillable = [
        'project_id',
        'user_id',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
