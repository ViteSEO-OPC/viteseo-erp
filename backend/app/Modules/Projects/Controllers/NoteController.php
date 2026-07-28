<?php

declare(strict_types=1);

namespace App\Modules\Projects\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Projects\Models\Note;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Requests\SaveNoteRequest;
use App\Modules\Projects\Resources\NoteResource;
use App\Modules\Projects\Services\NoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Private rich-text notes.
 *
 * Every route resolves the note from the authenticated user — no note id is
 * ever accepted from the client, so there is no way to address someone else's
 * note. The only project-level check is membership (ProjectPolicy@view).
 */
class NoteController extends Controller
{
    public function __construct(private readonly NoteService $notes) {}

    /** The caller's user-scoped note (created empty on first access). */
    public function showMine(Request $request): JsonResponse
    {
        return $this->note($this->notes->resolveUserNote($request->user()));
    }

    public function updateMine(SaveNoteRequest $request): JsonResponse
    {
        $note = $this->notes->save(
            $this->notes->resolveUserNote($request->user()),
            $request->document(),
            $request->baseVersion(),
        );

        return $this->saved($note->version, $note->last_saved_at?->toIso8601String());
    }

    /** The caller's own note for this project. */
    public function showMineForProject(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return $this->note($this->notes->resolveProjectNote($project, $request->user()));
    }

    public function updateMineForProject(SaveNoteRequest $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $note = $this->notes->save(
            $this->notes->resolveProjectNote($project, $request->user()),
            $request->document(),
            $request->baseVersion(),
        );

        return $this->saved($note->version, $note->last_saved_at?->toIso8601String());
    }

    /**
     * A note payload. Forced to 200: a resource whose model was just created
     * would otherwise answer a GET with 201, which reads as "created" to any
     * client inspecting the status.
     */
    private function note(Note $note): JsonResponse
    {
        return (new NoteResource($note))->response()->setStatusCode(200);
    }

    /**
     * The autosave acknowledgement — just the new concurrency token and stamp.
     */
    private function saved(int $version, ?string $lastSavedAt): JsonResponse
    {
        return response()->json([
            'data' => [
                'version' => $version,
                'last_saved_at' => $lastSavedAt,
            ],
        ]);
    }
}
