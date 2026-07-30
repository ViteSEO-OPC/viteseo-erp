<?php

declare(strict_types=1);

namespace App\Modules\Projects\Services;

use App\Models\User;
use App\Modules\Projects\Enums\NoteScope;
use App\Modules\Projects\Exceptions\NoteVersionConflictException;
use App\Modules\Projects\Models\Note;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Support\TiptapDocument;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class NoteService
{
    /**
     * The caller's user-scoped note, created empty on first access so the
     * frontend never has to handle a 404.
     */
    public function resolveUserNote(User $user): Note
    {
        return $this->firstOrCreate([
            'scope' => NoteScope::USER->value,
            'user_id' => $user->id,
            'project_id' => null,
        ]);
    }

    /**
     * The caller's own note for one project. Membership is checked by the
     * caller (the controller) — this method never widens access.
     */
    public function resolveProjectNote(Project $project, User $user): Note
    {
        $note = $this->firstOrCreate([
            'scope' => NoteScope::PROJECT->value,
            'user_id' => $user->id,
            'project_id' => $project->id,
        ]);

        // The project is already in hand — hand it to the resource so it can
        // expose the public uuid without a second query.
        return $note->setRelation('project', $project);
    }

    /**
     * Persist a new revision of a note.
     *
     * The version is re-read under a row lock so two saves racing from the same
     * user can't both write revision N+1 — the loser gets the conflict response
     * instead of silently overwriting.
     *
     * @param  array<string, mixed>  $content
     *
     * @throws NoteVersionConflictException when $baseVersion is behind the server
     */
    public function save(Note $note, array $content, int $baseVersion): Note
    {
        return DB::transaction(function () use ($note, $content, $baseVersion): Note {
            /** @var Note $fresh */
            $fresh = Note::query()->whereKey($note->getKey())->lockForUpdate()->firstOrFail();

            if ($baseVersion !== $fresh->version) {
                throw new NoteVersionConflictException($fresh);
            }

            $fresh->update([
                'content' => $content,
                'content_text' => TiptapDocument::extractText($content),
                'version' => $fresh->version + 1,
                'last_saved_at' => now(),
            ]);

            return $fresh;
        });
    }

    /**
     * Lazily create the note for a scope key.
     *
     * A user note has a null project_id, and MySQL treats NULLs as distinct in
     * a unique index, so the table can't stop a duplicate there. Concurrent
     * first-loads (dashboard + task panel mounting together) are resolved by
     * catching the race and re-reading rather than by locking every read.
     *
     * @param  array<string, mixed>  $keys
     */
    private function firstOrCreate(array $keys): Note
    {
        $existing = Note::query()->where($this->whereKeys($keys))->orderBy('id')->first();
        if ($existing !== null) {
            return $existing;
        }

        try {
            return Note::create($keys + [
                'content' => TiptapDocument::empty(),
                'content_text' => '',
                'version' => 1,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another request won the race — its row is the note.
            return Note::query()->where($this->whereKeys($keys))->orderBy('id')->firstOrFail();
        }
    }

    /**
     * `where()` can't take a null value in an array form, so split it out.
     *
     * @param  array<string, mixed>  $keys
     */
    private function whereKeys(array $keys): \Closure
    {
        return function ($query) use ($keys): void {
            foreach ($keys as $column => $value) {
                $value === null
                    ? $query->whereNull($column)
                    : $query->where($column, $value);
            }
        };
    }
}
