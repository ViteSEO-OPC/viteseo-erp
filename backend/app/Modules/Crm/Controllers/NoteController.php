<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;

use App\Modules\Crm\Requests\StoreNoteRequest;
use App\Modules\Crm\Requests\UpdateNoteRequest;
use App\Modules\Crm\Resources\NoteResource;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\Note;
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

    /** Add a note (author = current user). Logs `note.created`. */
    public function store(StoreNoteRequest $request, Lead $lead): JsonResponse
    {
        $note = $lead->notes()->make(['body' => (string) $request->validated()['body']]);
        $note->author()->associate($request->user());
        $note->save();

        return (new NoteResource($note->load('author')))->response()->setStatusCode(201);
    }

    /** Edit a note (author/Admin/SA). Route scopes {note} to {lead}. Logs `note.updated`. */
    public function update(UpdateNoteRequest $request, Lead $lead, Note $note): JsonResponse
    {
        $note->update(['body' => (string) $request->validated()['body']]);

        return (new NoteResource($note->load('author')))->response();
    }

    /** Soft-delete a note (author/Admin/SA). Logs `note.deleted`. */
    public function destroy(Lead $lead, Note $note): JsonResponse
    {
        $note->delete();

        return response()->json(null, 204);
    }
}
