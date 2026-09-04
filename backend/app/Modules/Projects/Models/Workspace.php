<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Models\Concerns\BelongsToOrg;
use App\Modules\Projects\Concerns\HasPublicUuid;
use App\Modules\Projects\Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @mixin \Eloquent
 */
class Workspace extends Model
{
    use BelongsToOrg;
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory, HasPublicUuid, SoftDeletes;

    protected static function newFactory(): WorkspaceFactory
    {
        return WorkspaceFactory::new();
    }

    protected $table = 'pm_workspaces';

    protected $fillable = [
        'name',
        'description',
    ];

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'workspace_id');
    }
}
