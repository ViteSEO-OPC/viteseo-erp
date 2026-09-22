<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;

use App\Modules\Crm\Requests\StoreNoteRequest;
use App\Modules\Crm\Requests\UpdateNoteRequest;
use App\Modules\Crm\Resources\NoteResource;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\Note;
use App\Modules\Crm\Services\LeadNoteCreator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Internal notes for a lead. Read/create authorize through the parent lead (ownership via
 * LeadPolicy@view on the route); edit/delete authorize through NotePolicy (author/Admin/SA).
 * Every write logs a `note.*` Activity via NoteObserver — the controller stays thin.
 */
class NoteController extends Controller
{
    /** Notes for a lead, oldest → newest (chronological). */
    public function index(Lead $lead): AnonymousResourceCollection
    {
        return NoteResource::collection(
            $lead->notes()->with('author')->get(),
        );
    }

    /**
     * Add a note (author = current user). Logs `note.created`.
     *
     * Delegates to {@see LeadNoteCreator}, the shared writer the Create Lead wizard's initial note
     * also uses, so both entry points attach a note identically.
     */
    public function store(StoreNoteRequest $request, Lead $lead, LeadNoteCreator $notes): JsonResponse
    {
        $note = $notes->create(
            $lead,
            (string) $request->validated()['body'],
            $request->user(),
            $request->noteDocument(),
        );

        return (new NoteResource($note->load('author')))->response()->setStatusCode(201);
    }

    /**
     * Edit a note (author/Admin/SA). Route scopes {note} to {lead}. Logs `note.updated`.
     *
     * Routed through {@see LeadNoteCreator::apply()} rather than a bare `update()` so the create and
     * edit paths share ONE definition of how a document becomes stored columns — in particular the
     * rule that `body` is re-derived server-side and never taken from the client.
     */
    public function update(
        UpdateNoteRequest $request,
        Lead $lead,
        Note $note,
        LeadNoteCreator $notes,
    ): JsonResponse {
        $notes->apply($note, (string) $request->validated()['body'], $request->noteDocument());

        return (new NoteResource($note->load('author')))->response();
    }

    /** Soft-delete a note (author/Admin/SA). Logs `note.deleted`. */
    public function destroy(Lead $lead, Note $note): JsonResponse
    {
        $note->delete();

        return response()->json(null, 204);
    }
}
