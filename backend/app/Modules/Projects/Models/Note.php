<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Models\User;
use App\Modules\Projects\Concerns\HasPublicUuid;
use App\Modules\Projects\Enums\NoteScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A private rich-text note (Tiptap JSON), owned by exactly one user.
 *
 * @mixin \Eloquent
 */
class Note extends Model
{
    use HasPublicUuid;

    protected $table = 'pm_notes';

    protected $fillable = [
        'scope',
        'user_id',
        'project_id',
        'content',
        'content_text',
        'version',
        'last_saved_at',
    ];

    protected $casts = [
        'scope' => NoteScope::class,
        'content' => 'array',
        'version' => 'integer',
        'last_saved_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * The caller's single user-scoped note.
     *
     * @param  Builder<Note>  $query
     * @return Builder<Note>
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('scope', NoteScope::USER)
            ->where('user_id', $userId)
            ->whereNull('project_id');
    }

    /**
     * The caller's note for one project. Always both keys — a project note is
     * identified by (project, user), never by project alone.
     *
     * @param  Builder<Note>  $query
     * @return Builder<Note>
     */
    public function scopeForProjectUser(Builder $query, int $projectId, int $userId): Builder
    {
        return $query->where('scope', NoteScope::PROJECT)
            ->where('project_id', $projectId)
            ->where('user_id', $userId);
    }
}
